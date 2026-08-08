<?php

namespace App\Services\Messaging\Providers;

use App\Models\Channel;
use App\Services\Messaging\Data\OutgoingMessageResult;
use App\Services\Messaging\SmsProviderInterface;
use Illuminate\Support\Facades\Http;

/**
 * https://smsaero.ru/integration/documentation/api/ — v2, HTTP Basic Auth
 * (email:api_key), эндпоинт ожидает application/x-www-form-urlencoded.
 */
class SmsAeroProvider implements SmsProviderInterface
{
    private const BASE_URL = 'https://gate.smsaero.ru/v2';

    public function sendSms(Channel $channel, string $phone, string $body): OutgoingMessageResult
    {
        $email = $channel->credentials['email'] ?? '';
        $apiKey = $channel->credentials['api_key'] ?? '';
        $sign = $channel->credentials['sign'] ?? null;

        $response = Http::withBasicAuth($email, $apiKey)
            ->asForm()
            ->post(self::BASE_URL . '/sms/send', array_filter([
                'number' => $phone,
                'text' => $body,
                'sign' => $sign,
            ]));

        if ($response->successful() && ($response->json('success') ?? false)) {
            return new OutgoingMessageResult(
                success: true,
                externalMessageId: (string) ($response->json('data.id') ?? ''),
            );
        }

        return new OutgoingMessageResult(
            success: false,
            error: $response->json('message') ?? "HTTP {$response->status()}",
        );
    }
}
