<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\SendMessageJob;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Client;
use Illuminate\Http\Request;

/**
 * Чат с клиентом — из карточки клиента (Фаза 11.3, базовый вариант). Отправка
 * идёт через очередь (SendMessageJob), сама HTTP-ручка только создаёт запись
 * сообщения со статусом queued и сразу возвращает управление.
 */
class ChatController extends Controller
{
    public function forClient(Client $client)
    {
        $chats = Chat::where('type', 'external')
            ->where('client_id', $client->id)
            ->with(['channel:id,name,messenger_type,provider', 'messages' => fn ($q) => $q->orderBy('id')->limit(200)])
            ->orderByDesc('last_message_at')
            ->get();

        return response()->json(['chats' => $chats]);
    }

    public function send(Request $request, Client $client)
    {
        $validated = $request->validate([
            'channel_id' => ['required', 'exists:channels,id'],
            'content' => ['required', 'string', 'max:4096'],
        ]);

        $channel = Channel::findOrFail($validated['channel_id']);

        // withTrashed() — та же причина, что и в MessengerWebhookController: без
        // него мягко удалённый чат не находится дефолтным scope и firstOrCreate()
        // падает на уникальном индексе при повторном создании.
        $chat = Chat::withTrashed()->firstOrCreate(
            ['type' => 'external', 'channel_id' => $channel->id, 'client_id' => $client->id],
            ['branch_id' => $channel->branch_id]
        );

        if ($chat->trashed()) {
            $chat->restore();
        }

        $message = $chat->messages()->create([
            'sender_type' => 'user',
            'sender_user_id' => auth()->id(),
            'direction' => 'out',
            'content' => $validated['content'],
            'status' => 'pending',
        ]);

        SendMessageJob::dispatch($message->id);

        return response()->json(['message' => $message, 'chat_id' => $chat->id]);
    }
}
