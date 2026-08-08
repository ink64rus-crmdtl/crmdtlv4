<?php

namespace App\Services\Messaging\Data;

final class OutgoingMessageResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $error = null,
    ) {
    }
}
