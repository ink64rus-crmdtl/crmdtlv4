<?php

namespace App\Services\Messaging;

use App\Models\Channel;
use App\Services\Messaging\Providers\WappiProProvider;
use InvalidArgumentException;

class MessengerProviderFactory
{
    /**
     * Единственное место в системе, которое знает соответствие Channel.provider
     * → конкретный класс. Переезд на другого провайдера — новая ветка здесь плюс
     * новый класс implements MessengerProviderInterface, без изменений где-либо ещё.
     */
    public static function make(Channel $channel): MessengerProviderInterface
    {
        return match ($channel->provider) {
            'wappi_pro' => app(WappiProProvider::class),
            default => throw new InvalidArgumentException("Неизвестный провайдер мессенджера: {$channel->provider}"),
        };
    }
}
