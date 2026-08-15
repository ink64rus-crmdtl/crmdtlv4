<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Task;
use App\Notifications\TaskReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Фаза 17, этап 2 — напоминания о приближающемся сроке задачи. Тот же
 * принцип, что и у SendAppointmentReminders: узкое окно "за N часов до
 * срока", сравнение в UTC, без учёта часового пояса (нужен только для текста,
 * а тут напоминание уходит не клиенту, а внутреннему пользователю системы).
 * Запускается тем же 15-минутным тактом, что и appointments:send-reminders.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders';

    protected $description = 'Отправить напоминания о задачах с приближающимся сроком (Setting task_reminder_hours_before, по умолчанию 2)';

    public function handle(): int
    {
        $hoursBefore = (float) (Setting::where('key', 'task_reminder_hours_before')->value('value') ?? 2);
        $windowEnd = Carbon::now()->addHours($hoursBefore);

        $tasks = Task::whereNull('reminder_sent_at')
            ->whereNull('completed_at')
            ->whereNotNull('assigned_to_user_id')
            ->whereNotNull('due_at')
            ->where('due_at', '>', Carbon::now())
            ->where('due_at', '<=', $windowEnd)
            ->with('assignedTo', 'taskable')
            ->get();

        $sent = 0;

        foreach ($tasks as $task) {
            if (! $task->assignedTo) {
                continue;
            }

            $task->assignedTo->notify(new TaskReminderNotification($task));
            $task->update(['reminder_sent_at' => now()]);
            $sent++;
        }

        $this->info("Отправлено напоминаний о задачах: {$sent}");

        return self::SUCCESS;
    }
}
