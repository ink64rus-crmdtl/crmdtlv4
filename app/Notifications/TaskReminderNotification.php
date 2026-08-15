<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Фаза 17, этап 2 — напоминание о приближающемся сроке задачи. Мирроринг
 * ExportReadyNotification: только database-канал, тот же bell-иконка
 * в AppHeader.vue, что уже умеет читать уведомления (см. NotificationController).
 */
class TaskReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Task $task) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $dueLabel = $this->task->due_at?->format('d.m.Y H:i');
        $context = $this->task->taskableLabel();

        return [
            'task_id' => $this->task->id,
            'message' => "Задача «{$this->task->title}»".($dueLabel ? " — срок {$dueLabel}" : '').($context ? " ({$context})" : ''),
            // Генерический переход по уведомлению (AppHeader.vue): именованный
            // роут + параметры вместо готового URL — тенантский домен в
            // уведомлении не хранится, ссылка строится на фронте через route().
            'link' => $this->task->taskable
                ? ['route' => $this->routeFor($this->task->taskable_type), 'params' => $this->task->taskable_id]
                : ['route' => 'tasks.index', 'params' => null],
        ];
    }

    private function routeFor(?string $taskableType): string
    {
        return match ($taskableType) {
            'App\\Models\\Deal' => 'sales.deals.show',
            'App\\Models\\Client' => 'crm.clients.show',
            'App\\Models\\WorkOrder' => 'operations.work-orders.show',
            'App\\Models\\Vehicle' => 'crm.vehicles.show',
            default => 'tasks.index',
        };
    }
}
