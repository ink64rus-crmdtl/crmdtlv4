<?php

namespace App\Services\Messaging;

use App\Jobs\SendMessageJob;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Client;
use App\Models\Message;

/**
 * Единая точка "найти/создать внешний чат с клиентом по каналу и поставить
 * исходящее сообщение в очередь" — раньше этот код жил только внутри
 * ChatController::send() (ручная отправка менеджером из карточки клиента);
 * с приходом автотриггеров по шаблонам (Фаза 11.1) появился второй вызывающий,
 * поэтому логика вынесена сюда, чтобы не дублировать firstOrCreate/withTrashed.
 */
class ChatDispatchService
{
    public static function sendToClient(Client $client, Channel $channel, string $body, ?int $senderUserId = null): Message
    {
        // withTrashed() — мягко удалённый чат (SoftDeletes) не виден дефолтному
        // scope, и firstOrCreate() упал бы на уникальном индексе
        // (channel_id, external_chat_id) при повторном INSERT — та же причина,
        // что в MessengerWebhookController.
        $chat = Chat::withTrashed()->firstOrCreate(
            ['type' => 'external', 'channel_id' => $channel->id, 'client_id' => $client->id],
            ['branch_id' => $channel->branch_id]
        );

        if ($chat->trashed()) {
            $chat->restore();
        }

        $message = $chat->messages()->create([
            'sender_type' => $senderUserId ? 'user' : 'system',
            'sender_user_id' => $senderUserId,
            'direction' => 'out',
            'content' => $body,
            'status' => 'pending',
        ]);

        SendMessageJob::dispatch($message->id);

        return $message;
    }

    /**
     * Канал по умолчанию для автотриггеров (напоминания, "заказ готов") — нет
     * понятия "предпочитаемый канал клиента", поэтому берём первый активный
     * канал, доступный точке (свой у точки или общий на тенант, branch_id
     * null). При нескольких подходящих — тот, что настроен раньше (id).
     */
    public static function defaultChannelFor(?int $branchId): ?Channel
    {
        return Channel::where('is_active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->orderByRaw('branch_id IS NULL')
            ->orderBy('id')
            ->first();
    }
}
