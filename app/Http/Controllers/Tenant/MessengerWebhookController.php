<?php

namespace App\Http\Controllers\Tenant;

use App\Events\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Client;
use App\Services\Messaging\MessengerProviderFactory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Единая точка приёма вебхуков от ЛЮБОГО мессенджер-провайдера. URL содержит
 * webhook_token конкретного Channel — так провайдер сообщает, к какому именно
 * подключённому номеру относится событие, и это же служит защитой от подделки
 * (токен непубличный, не совпадает с provider/profile_id, которые могут быть
 * известны третьим лицам). Сам разбор payload'а — целиком на стороне конкретной
 * реализации MessengerProviderInterface, контроллер не знает формат Wappi/Green API.
 */
class MessengerWebhookController extends Controller
{
    public function handle(Request $request, string $provider, string $webhookToken): JsonResponse
    {
        $channel = Channel::where('webhook_token', $webhookToken)
            ->where('provider', $provider)
            ->first();

        if (!$channel) {
            return response()->json(['error' => 'unknown channel'], 404);
        }

        $providerImpl = MessengerProviderFactory::make($channel);

        $newStatus = $providerImpl->syncStatusFromWebhook($channel, $request);
        if ($newStatus && $newStatus !== $channel->status) {
            $channel->update(['status' => $newStatus]);
        }

        $incoming = $providerImpl->parseIncomingWebhook($channel, $request);

        if (!$incoming) {
            return response()->json(['ok' => true]);
        }

        $client = $this->findClientByPhone($incoming->senderPhone);

        // withTrashed() — иначе если чат когда-то мягко удалили (Chat использует
        // SoftDeletes), а клиент написал снова на тот же external_chat_id,
        // firstOrCreate() его "не увидит" (дефолтный scope прячет trashed) и
        // упадёт на уникальном индексе (channel_id, external_chat_id) при INSERT.
        $chat = Chat::withTrashed()->firstOrCreate(
            ['channel_id' => $channel->id, 'external_chat_id' => $incoming->externalChatId],
            ['type' => 'external', 'branch_id' => $channel->branch_id, 'client_id' => $client?->id]
        );

        if ($chat->trashed()) {
            $chat->restore();
        }

        if ($client && !$chat->client_id) {
            $chat->update(['client_id' => $client->id]);
        }

        // Провайдеры повторно доставляют вебхук, если не получили быстрый 2xx
        // (таймаут, временная недоступность и т.п.) — без этой проверки повтор
        // создал бы дубликат сообщения.
        if ($incoming->externalMessageId && $chat->messages()->where('external_message_id', $incoming->externalMessageId)->exists()) {
            return response()->json(['ok' => true]);
        }

        $message = $chat->messages()->create([
            'sender_type' => 'client',
            'direction' => 'in',
            'content' => $incoming->body,
            'attachments' => $incoming->attachments,
            'external_message_id' => $incoming->externalMessageId,
            'status' => 'delivered',
        ]);

        $chat->update(['last_message_at' => $message->created_at]);

        broadcast(new MessageReceived($message));

        return response()->json(['ok' => true]);
    }

    /**
     * Провайдеры отдают телефон в "сыром" виде (79001234567, без +), а в
     * clients.phone он может лежать в любом человеческом формате (+7 900 123-45-67
     * и т.п.) — точное сравнение строк почти никогда не совпадёт. Сравниваем по
     * последним 10 цифрам (стабильны для российского номера независимо от 7/8/+7),
     * отбрасывая всё нецифровое с обеих сторон.
     */
    private function findClientByPhone(string $rawPhone): ?Client
    {
        $digits = preg_replace('/\D+/', '', $rawPhone);
        $last10 = substr($digits, -10);

        if (strlen($last10) < 10) {
            return null;
        }

        return Client::whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?",
            ["%{$last10}"]
        )->first();
    }
}
