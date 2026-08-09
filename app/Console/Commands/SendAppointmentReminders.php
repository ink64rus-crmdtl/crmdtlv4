<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Services\Messaging\ChatDispatchService;
use App\Services\Messaging\MessageTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Фаза 11.1, триггер "appointment.reminder". В отличие от снэпшотов/начисления
 * ЗП здесь не нужна логика "у какого часового пояса сейчас полночь" — окно
 * "за N часов до start_at" сравнивается в UTC как есть, часовой пояс точки
 * нужен только для ТЕКСТА напоминания (см. MessageTemplateService::renderForAppointment).
 * Запускается раз в 15 минут (bootstrap/app.php) — реже, чем ежечасные джобы,
 * т.к. окно напоминания может быть узким и час точности тут ощутимо заметен клиенту.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Отправить автонапоминания о записях, начинающихся в ближайшие N часов (Setting appointment_reminder_hours_before, по умолчанию 24)';

    public function handle(): int
    {
        $template = MessageTemplate::where('event_trigger', 'appointment.reminder')->where('is_active', true)->first();
        if (!$template) {
            return self::SUCCESS;
        }

        $hoursBefore = (float) (Setting::where('key', 'appointment_reminder_hours_before')->value('value') ?? 24);
        $windowEnd = Carbon::now()->addHours($hoursBefore);

        $appointments = Appointment::whereNull('reminder_sent_at')
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('start_at', '>', Carbon::now())
            ->where('start_at', '<=', $windowEnd)
            ->with('client')
            ->get();

        $sent = 0;

        foreach ($appointments as $appointment) {
            if (!$appointment->client) {
                continue;
            }

            $channel = ChatDispatchService::defaultChannelFor($appointment->branch_id);
            if (!$channel) {
                continue;
            }

            ChatDispatchService::sendToClient($appointment->client, $channel, MessageTemplateService::renderForAppointment($template, $appointment));
            $appointment->update(['reminder_sent_at' => now()]);
            $sent++;
        }

        $this->info("Отправлено напоминаний: {$sent}");

        return self::SUCCESS;
    }
}
