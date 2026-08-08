<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Messaging\MessengerProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Отправка исходящего сообщения — сетевой вызов к провайдеру не должен
 * блокировать HTTP-запрос менеджера (CLAUDE.md §6, асинхронность тяжёлых
 * операций). QueueTenancyBootstrapper сам восстанавливает контекст тенанта
 * при разборе job'ы воркером — тут ничего дополнительно передавать не нужно.
 */
class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $messageId)
    {
    }

    public function handle(): void
    {
        $message = Message::with(['chat.channel', 'chat.client'])->find($this->messageId);

        if (!$message || !$message->chat || !$message->chat->channel) {
            return;
        }

        $channel = $message->chat->channel;

        // Wappi ожидает "голый" номер (страна+10 цифр, без +/пробелов), а в
        // clients.phone он в человеческом формате — приводим перед отправкой.
        $recipient = $message->chat->external_chat_id
            ?: ($message->chat->client?->phone ? preg_replace('/\D+/', '', $message->chat->client->phone) : null);

        if (!$recipient) {
            $message->update(['status' => 'failed']);
            return;
        }

        $provider = MessengerProviderFactory::make($channel);
        $result = $provider->sendText($channel, $recipient, $message->content);

        $message->update([
            'status' => $result->success ? 'sent' : 'failed',
            'external_message_id' => $result->externalMessageId ?? $message->external_message_id,
        ]);

        // ВАЖНО: провайдер в вебхуках отдаёт chatId в своём формате (у Wappi —
        // JID вида 79001234567@c.us), а не голым номером, который мы сюда
        // записали — поэтому если клиент СНАЧАЛА написал сам (webhook создал
        // Chat с external_chat_id=JID), а МЫ потом ответили, всё сольётся в один
        // Chat. Но если МЫ написали первыми (сохраняем голый номер, не JID) —
        // ответ клиента придёт вебхуком с другим external_chat_id (JID) и
        // создаст ВТОРОЙ Chat вместо продолжения этого. Известное упрощение
        // фундамента: переписка не потеряется (появится отдельной веткой), но
        // не всегда сольётся в одну — доработать, когда будет известен точный
        // формат chatId, отдаваемый Wappi на исходящие (не задокументирован).
        if ($result->success && !$message->chat->external_chat_id) {
            $message->chat->update(['external_chat_id' => $recipient]);
        }

        $message->chat->update(['last_message_at' => $message->created_at]);
    }
}
