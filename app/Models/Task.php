<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Задача (Фаза 17, этап 2) — ОБЩЕСИСТЕМНАЯ и ПОЛИМОРФНАЯ: может относиться
 * к Сделке, Клиенту, Заказ-наряду, Автомобилю — к чему угодно (taskable),
 * либо не относиться ни к чему (личное напоминание менеджера).
 */
class Task extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'taskable_type',
        'taskable_id',
        'assigned_to_user_id',
        'created_by',
        'type',
        'title',
        'description',
        'due_at',
        'completed_at',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isCompleted() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * Человекочитаемое название связанной записи для UI (карточка задачи,
     * список «Мои задачи») — единая точка, чтобы не разбрасывать
     * match-по-классу по фронту и бэку.
     */
    public function taskableLabel(): ?string
    {
        $entity = $this->taskable;

        if (! $entity) {
            return null;
        }

        return match (get_class($entity)) {
            Deal::class => "Сделка «{$entity->title}»",
            Client::class => $entity->name,
            WorkOrder::class => "Заказ №{$entity->id}",
            Vehicle::class => $entity->plate_number,
            default => null,
        };
    }
}
