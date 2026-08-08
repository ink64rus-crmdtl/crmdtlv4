<?php

namespace App\Services\Messaging\Providers;

use App\Models\Central\PlatformSetting;
use App\Models\Channel;
use App\Services\Messaging\Data\IncomingMessageData;
use App\Services\Messaging\Data\OutgoingMessageResult;
use App\Services\Messaging\MessengerProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * https://wappi.pro/api-documentation (WhatsApp), /telegram-api-documentation,
 * /max-api-documentation — все три мессенджера используют один и тот же паттерн
 * (Authorization-заголовок + profile_id в query на каждый запрос), различия
 * только в наборе эндпоинтов под конкретный мессенджер. Для MVP используются
 * только эндпоинты WhatsApp-раздела (общие для всех трёх мессенджеров Wappi) —
 * это будет верно ровно до тех пор, пока не понадобятся специфичные для
 * Telegram/MAX методы, тогда веткование пойдёт по $channel->messenger_type.
 *
 * Токен — ОДИН на весь корпоративный аккаунт Wappi (не на тенанта и не на
 * канал): тенант физически не имеет доступа к личному кабинету Wappi, поэтому
 * профиль там заводит сама система (см. provisionProfile()), а токен хранится
 * в central БД (App\Models\Central\PlatformSetting) и вносится администратором
 * платформы в /admin/settings. См. CLAUDE.md, Фаза 16.
 */
class WappiProProvider implements MessengerProviderInterface
{
    private const BASE_URL = 'https://wappi.pro/api';

    public function sendText(Channel $channel, string $recipient, string $body): OutgoingMessageResult
    {
        $response = $this->client()->post($this->url('/sync/message/send', $channel), [
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

        $response = $this->client()->post($this->url($endpoint, $channel), array_filter([
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
        $response = $this->client()->get($this->url('/sync/qr/get', $channel));

        if (!$response->successful()) {
            return null;
        }

        return $response->json('qr_code') ?? $response->json('base64') ?? null;
    }

    public function provisionProfile(Channel $channel, string $desiredName, string $webhookUrl): string
    {
        $existing = $this->findProfileIdByName($desiredName);
        if ($existing) {
            return $existing;
        }

        // Laravel Http-клиент НЕ бросает исключение сам по себе на 4xx/5xx —
        // без явного throw() ChannelController решил бы, что профиль создан,
        // даже если Wappi вернул ошибку (например, из-за неверного токена).
        $response = $this->client()
            ->post($this->url('/profile/add', null, ['name' => $desiredName, 'webhook_url' => $webhookUrl]))
            ->throw();

        $profileId = $response->json('profile_id');
        if (!$profileId) {
            throw new RuntimeException('Wappi.Pro не вернул profile_id при создании профиля.');
        }

        return (string) $profileId;
    }

    public function releaseProfile(Channel $channel): void
    {
        if (!$channel->external_profile_id) {
            return;
        }

        $this->client()->post($this->url('/profile/delete', $channel));
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

    /**
     * Дедупликация перед созданием профиля (защита от повторного клика
     * "Подключить"/ретрая после сетевого сбоя — см. ChannelController).
     * GET /profile/all/get формально требует profile_id в query (см.
     * разведку в плане), но семантика параметра для "списка ВСЕХ профилей"
     * в документации не разъяснена — пробуем без него; если Wappi всё же
     * ответит ошибкой, просто пропускаем дедуп (не блокируем создание) —
     * потеря защиты от дублей в этом редком edge-кейсе не критична, коллизия
     * имён между тенантами структурно невозможна (см. схему именования).
     */
    private function findProfileIdByName(string $name): ?string
    {
        try {
            $response = $this->client()->get('/profile/all/get');
            if (!$response->successful()) {
                return null;
            }

            $profiles = $response->json('profiles') ?? $response->json() ?? [];
            if (!is_array($profiles)) {
                return null;
            }

            foreach ($profiles as $profile) {
                if (is_array($profile) && ($profile['name'] ?? null) === $name) {
                    $id = $profile['profile_id'] ?? $profile['id'] ?? null;
                    return $id ? (string) $id : null;
                }
            }
        } catch (Throwable $e) {
            // сетевая ошибка на этапе дедупа — не блокируем провижининг.
        }

        return null;
    }

    private function stripJidSuffix(string $jid): string
    {
        return preg_replace('/@.+$/', '', $jid) ?? $jid;
    }

    private function client(): PendingRequest
    {
        $token = tenancy()->central(fn () => PlatformSetting::get('wappi_master_token'));

        return Http::withHeaders([
            'Authorization' => $token ?? '',
        ])->baseUrl(self::BASE_URL);
    }

    private function url(string $path, ?Channel $channel, array $extraQuery = []): string
    {
        $query = $channel ? array_merge(['profile_id' => $channel->external_profile_id], $extraQuery) : $extraQuery;

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
