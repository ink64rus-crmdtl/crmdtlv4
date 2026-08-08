<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Client;
use App\Services\Messaging\ChatDispatchService;
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

        $message = ChatDispatchService::sendToClient($client, $channel, $validated['content'], auth()->id());

        return response()->json(['message' => $message, 'chat_id' => $message->chat_id]);
    }
}
