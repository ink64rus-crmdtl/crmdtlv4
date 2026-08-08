<?php

namespace App\Services\Messaging\Providers;

use App\Models\Channel;
use App\Services\Messaging\Data\IncomingMessageData;
use App\Services\Messaging\Data\OutgoingMessageResult;
use App\Services\Messaging\MessengerProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * https://wappi.pro/api-documentation (WhatsApp), /telegram-api-documentation,
 * /max-api-documentation — все три мессенджера используют один и тот же паттерн
 * (Authorization-заголовок + profile_id в query на каждый запрос), различия
 * только в наборе эндпоинтов под конкретный мессенджер. Для MVP используются
 * только эндпоинты WhatsApp-раздела (общие для всех трёх мессенджеров Wappi) —
 * это будет верно ровно до тех пор, пока не понадобятся специфичные для
 * Telegram/MAX методы, тогда веткование пойдёт по $channel->messenger_type.
 */
class WappiProProvider implements MessengerProviderInterface
{
    private const BASE_URL = 'https://wappi.pro/api';

    public function sendText(Channel $channel, string $recipient, string $body): OutgoingMessageResult
    {
        $response = $this->client($channel)->post($this->url('/sync/message/send', $channel), [
            'recipient' => $recipient,
            'body' => $body,
        ]);

        return $this->toResult($response);
    }

    public function sendMedia(Channel $channel, string $recipient, string $type, string $url, ?string $caption = null): OutgoingMessageResult
    {
        $endpoint = match ($type) {
            'image' => '/sync/message/img/send',
            'document' => '/sync/message/document/send',
            'video' => '/sync/message/video/send',
            'audio' => '/sync/message/audio/send',
            default => '/sync/message/file/url/send',
        };

        $response = $this->client($channel)->post($this->url($endpoint, $channel), array_filter([
            'recipient' => $recipient,
            'url' => $url,
            'caption' => $caption,
        ]));

        return $this->toResult($response);
    }

    public function getConnectionStatus(Channel $channel): string
    {
        // Статус хранится локально и обновляется вебхуком authorization_status
        // (см. syncStatusFromWebhook) — лишний сетевой запрос на каждую проверку
        // не нужен, БД тут и есть источник правды.
        return $channel->status;
    }

    public function getQrCode(Channel $channel): ?string
    {
        $response = $this->client($channel)->get($this->url('/sync/qr/get', $channel));

        if (!$response->successful()) {
            return null;
        }

        return $response->json('qr_code') ?? $response->json('base64') ?? null;
    }

    public function registerWebhook(Channel $channel, string $webhookUrl): void
    {
        // Laravel Http-клиент НЕ бросает исключение сам по себе на 4xx/5xx —
        // без явного throw() ChannelController::store() решил бы, что вебхук
        // зарегистрирован успешно, даже если Wappi вернул ошибку (например,
        // из-за неверного токена/profile_id).
        $this->client($channel)
            ->post($this->url('/profile/add', $channel, ['webhook_url' => $webhookUrl]))
            ->throw();
    }

    public function parseIncomingWebhook(Channel $channel, Request $request): ?IncomingMessageData
    {
        $payload = $request->all();

        // Сообщения, отправленные с самого подключённого телефона (не через CRM,
        // например менеджер ответил руками) — не заводим как входящие в MVP.
        if (($payload['type'] ?? null) !== 'chat' || !empty($payload['fromMe'])) {
            return null;
        }

        $body = $payload['body'] ?? null;
        $from = $payload['from'] ?? null;

        if (!$from) {
            return null;
        }

        // Точный формат вебхука для медиа-сообщений (фото/документ/видео без
        // подписи) Wappi нигде не документирует — а значит "body" может
        // отсутствовать даже у реального сообщения от клиента. Раньше это
        // приводило к тому, что такие сообщения тихо терялись (return null,
        // провайдеру уходит 200 OK — повторной доставки не будет). Вместо
        // этого сохраняем плейсхолдер и весь сырой payload в attachments —
        // сообщение не потеряется, а маппинг медиа-полей можно уточнить
        // позже по реальным вебхукам, не теряя уже накопленные сообщения.
        $attachments = [];
        if (!$body) {
            $body = '[Медиа-сообщение]';
            $attachments = [['type' => 'unknown', 'raw' => $payload]];
        }

        return new IncomingMessageData(
            externalChatId: $payload['chatId'] ?? $from,
            senderPhone: $this->stripJidSuffix($from),
            body: $body,
            externalMessageId: $payload['id'] ?? null,
            attachments: $attachments,
        );
    }

    public function syncStatusFromWebhook(Channel $channel, Request $request): ?string
    {
        $payload = $request->all();

        if (!isset($payload['status']) || !array_key_exists('profile_id', $payload)) {
            return null;
        }

        // Wappi шлёт "online"/"offline" — приводим к нашему словарю статусов.
        return match ($payload['status']) {
            'online', 'authorized', 'connected' => 'connected',
            default => 'disconnected',
        };
    }

    private function stripJidSuffix(string $jid): string
    {
        return preg_replace('/@.+$/', '', $jid) ?? $jid;
    }

    private function client(Channel $channel): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $channel->credentials['token'] ?? '',
        ])->baseUrl(self::BASE_URL);
    }

    private function url(string $path, Channel $channel, array $extraQuery = []): string
    {
        $query = array_merge(['profile_id' => $channel->external_profile_id], $extraQuery);

        return $path . '?' . http_build_query($query);
    }

    private function toResult(Response $response): OutgoingMessageResult
    {
        if ($response->successful()) {
            return new OutgoingMessageResult(
                success: true,
                externalMessageId: $response->json('id') ?? $response->json('message_id'),
            );
        }

        return new OutgoingMessageResult(
            success: false,
            error: $response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}",
        );
    }
}
