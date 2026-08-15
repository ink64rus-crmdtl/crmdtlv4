<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Фаза 17, этап 3 — действие, автоматически выполняемое при входе сделки
 * в стадию (см. App\Services\Sales\PipelineAutomationService). action
 * определяет, какие из остальных полей реально используются:
 *   send_message        — message_template_id (обязателен);
 *   create_task          — task_title/task_due_offset_days (оба опциональны,
 *                          есть текст по умолчанию);
 *   create_appointment    — те же task_title/task_due_offset_days: НЕ создаёт
 *                          реальный Appointment (start_at/end_at обязательны
 *                          в схеме записей, а времени тут взять неоткуда) —
 *                          вместо этого ставит задачу-напоминание
 *                          «запланировать визит», менеджер сам выбирает время.
 */
class PipelineStageAutomation extends Model
{
    public const ACTION_SEND_MESSAGE = 'send_message';

    public const ACTION_CREATE_TASK = 'create_task';

    public const ACTION_CREATE_APPOINTMENT = 'create_appointment';

    public const ACTIONS = [
        self::ACTION_SEND_MESSAGE => 'Отправить сообщение клиенту',
        self::ACTION_CREATE_TASK => 'Поставить задачу',
        self::ACTION_CREATE_APPOINTMENT => 'Напомнить запланировать визит',
    ];

    protected $fillable = [
        'pipeline_stage_id',
        'action',
        'message_template_id',
        'task_title',
        'task_due_offset_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'task_due_offset_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }
}
