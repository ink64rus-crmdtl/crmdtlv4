<?php

namespace App\Services\Messaging\Data;

final class IncomingMessageData
{
    /**
     * @param array<int, array{type: string, url: string}> $attachments
     */
    public function __construct(
        public readonly string $externalChatId,
        public readonly string $senderPhone,
        public readonly string $body,
        public readonly ?string $externalMessageId = null,
        public readonly array $attachments = [],
    ) {
    }
}
