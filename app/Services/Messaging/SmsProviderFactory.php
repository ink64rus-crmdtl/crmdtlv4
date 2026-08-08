<?php

namespace App\Services\Messaging;

use App\Models\Channel;
use App\Services\Messaging\Providers\SmsAeroProvider;
use InvalidArgumentException;

class SmsProviderFactory
{
    public static function make(Channel $channel): SmsProviderInterface
    {
        return match ($channel->provider) {
            'sms_aero' => app(SmsAeroProvider::class),
            default => throw new InvalidArgumentException("Неизвестный SMS-провайдер: {$channel->provider}"),
        };
    }
}
