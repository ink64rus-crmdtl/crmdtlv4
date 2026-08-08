<?php

namespace App\Services\Messaging;

use App\Models\Channel;
use App\Services\Messaging\Data\IncomingMessageData;
use App\Services\Messaging\Data\OutgoingMessageResult;
use Illuminate\Http\Request;

/**
 * Единая точка контакта с любым чат-провайдером (WhatsApp/Telegram/MAX и т.п.).
 * Остальной код системы (SendMessageJob, ChannelController, вебхук-контроллер)
 * работает только через этот интерфейс — переезд с Wappi.Pro на другого
 * провайдера (например Green API) означает написать новый класс
 * implements MessengerProviderInterface и завести его в MessengerProviderFactory,
 * ничего больше в системе менять не нужно.
 */
interface MessengerProviderInterface
{
    /**
     * $recipient — номер телефона (79001234567) либо chat_id провайдера.
     */
    public function sendText(Channel $channel, string $recipient, string $body): OutgoingMessageResult;

    /**
     * $type — image, document, video, audio. $url — публично доступная ссылка на файл.
     */
    public function sendMedia(Channel $channel, string $recipient, string $type, string $url, ?string $caption = null): OutgoingMessageResult;

    /**
     * Текущий статус подключения канала: pending (ещё не авторизован) / connected / disconnected.
     */
    public function getConnectionStatus(Channel $channel): string;

    /**
     * QR-код для подключения (base64), null — если у провайдера нет такого способа
     * авторизации (например SMS не нужен QR).
     */
    public function getQrCode(Channel $channel): ?string;

    /**
     * Создать (или переиспользовать уже существующее по имени — защита от
     * дублей при ретрае после сбоя) подключение у провайдера и сразу
     * зарегистрировать вебхук. Вызывается ОДИН раз, при первом подключении
     * канала — возвращает внешний идентификатор для сохранения в
     * Channel.external_profile_id. $desiredName — по конвенции
     * "{tenant_id}-{channel_id}", формирует вызывающий код (ChannelController),
     * не сам провайдер — провайдеру всё равно, откуда взялось имя.
     */
    public function provisionProfile(Channel $channel, string $desiredName, string $webhookUrl): string;

    /**
     * Освободить подключение у провайдера (при удалении канала) — best-effort,
     * чтобы отключённые номера не копились в общем аккаунте навсегда.
     */
    public function releaseProfile(Channel $channel): void;

    /**
     * Разобрать входящий вебхук как СООБЩЕНИЕ. Null — это не сообщение (например
     * статус доставки или служебное событие) либо сообщение не из этого чата.
     */
    public function parseIncomingWebhook(Channel $channel, Request $request): ?IncomingMessageData;

    /**
     * Разобрать входящий вебхук как ИЗМЕНЕНИЕ СТАТУСА ПОДКЛЮЧЕНИЯ канала
     * (например пользователь отсканировал QR или разлогинил WhatsApp с телефона).
     * Возвращает новый статус ('connected'/'disconnected') или null, если это
     * не событие статуса.
     */
    public function syncStatusFromWebhook(Channel $channel, Request $request): ?string;
}
