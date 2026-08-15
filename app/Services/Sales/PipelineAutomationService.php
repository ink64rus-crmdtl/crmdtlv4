<?php

namespace App\Services\Sales;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\PipelineStageAutomation;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\Messaging\ChatDispatchService;
use App\Services\Messaging\MessageTemplateService;

/**
 * Фаза 17, этап 3 — действия, автоматически выполняемые при входе сделки в
 * стадию (DealController::store()/moveStage(), WorkOrderController::
 * autoWinLinkedDeal()). Выполняется СИНХРОННО, не через очередь: действий
 * немного и они лёгкие — само сообщение клиенту всё равно уходит в очередь
 * через SendMessageJob (см. ChatDispatchService), заводить джоб ради
 * тонкой обёртки вокруг него excessive.
 */
class PipelineAutomationService
{
    public static function runFor(Deal $deal, PipelineStage $stage): void
    {
        $automations = $stage->automations()->where('is_active', true)->get();

        foreach ($automations as $automation) {
            match ($automation->action) {
                PipelineStageAutomation::ACTION_SEND_MESSAGE => self::sendMessage($deal, $automation),
                PipelineStageAutomation::ACTION_CREATE_TASK => self::createTask($deal, $automation, false),
                PipelineStageAutomation::ACTION_CREATE_APPOINTMENT => self::createTask($deal, $automation, true),
                default => null,
            };
        }
    }

    private static function sendMessage(Deal $deal, PipelineStageAutomation $automation): void
    {
        if (! $deal->client || ! $automation->messageTemplate) {
            return;
        }

        $channel = ChatDispatchService::defaultChannelFor($deal->branch_id);
        if (! $channel) {
            return;
        }

        ChatDispatchService::sendToClient($deal->client, $channel, MessageTemplateService::renderForDeal($automation->messageTemplate, $deal));

        ActivityLogger::log(
            $deal,
            "Автоматически отправлено сообщение клиенту по шаблону «{$automation->messageTemplate->name}»",
            [],
            'automation'
        );
    }

    /**
     * create_appointment решено (см. CLAUDE.md) НЕ создавать реальный
     * Appointment автоматически — start_at/end_at обязательны, а времени
     * автоматизация не знает и не хранит. Вместо этого ставится
     * задача-напоминание «запланировать визит», менеджер сам выбирает время.
     */
    private static function createTask(Deal $deal, PipelineStageAutomation $automation, bool $isAppointmentReminder): void
    {
        $title = $automation->task_title
            ?: ($isAppointmentReminder ? "Запланировать визит по сделке «{$deal->title}»" : "Задача по сделке «{$deal->title}»");

        $task = Task::create([
            'branch_id' => $deal->branch_id,
            'taskable_type' => Deal::class,
            'taskable_id' => $deal->id,
            'assigned_to_user_id' => $deal->owner_user_id,
            'title' => $title,
            'due_at' => $automation->task_due_offset_days !== null ? now()->addDays($automation->task_due_offset_days) : null,
        ]);

        ActivityLogger::log(
            $deal,
            "Автоматически поставлена задача: «{$task->title}»".($task->due_at ? ' (срок '.$task->due_at->format('d.m.Y').')' : ''),
            [],
            'automation'
        );
    }
}
