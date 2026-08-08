<?php

namespace App\Services\Messaging;

use App\Models\Channel;
use App\Services\Messaging\Data\OutgoingMessageResult;

/**
 * Намеренно отдельно от MessengerProviderInterface: у SMS нет диалога/QR-подключения/
 * входящих вебхуков-сообщений в базовом варианте — это просто отправка, а не чат.
 * Впихивать это в общий интерфейс мессенджеров означало бы держать там методы-заглушки
 * (getQrCode всегда null и т.п.) ради формального единообразия — не стоит того.
 */
interface SmsProviderInterface
{
    public function sendSms(Channel $channel, string $phone, string $body): OutgoingMessageResult;
}
