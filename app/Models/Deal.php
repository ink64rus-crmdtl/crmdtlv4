<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLog;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Сделка (Фаза 17) — переговоры о намерении, ОТДЕЛЬНАЯ сущность между
 * Клиентом и Заказ-нарядом. Продолжение принципа Фазы 9 «Запись (намерение)
 * ≠ Заказ-наряд (факт)», на шаг раньше.
 *
 * НЕ ТРОГАЕТ склад и финансы: у сделки нет ни StockMovement, ни Transaction,
 * ни расчёта ЗП. Позиции (DealItem) — только предварительная смета для КП.
 */
class Deal extends Model
{
    use HasActivityLog, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'legal_entity_id',
        'client_id',
        'vehicle_id',
        'pipeline_id',
        'pipeline_stage_id',
        'owner_user_id',
        'title',
        'amount',
        'expected_close_date',
        'stage_entered_at',
        'source_lookup_id',
        'loss_reason_lookup_id',
        'appointment_id',
        'work_order_id',
        'closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expected_close_date' => 'date',
            'stage_entered_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    /**
     * Ответственный менеджер. Без BranchScope/SoftDeletes-фильтра по той же
     * причине, что и связи «кому платим» (CLAUDE.md §8): назначенный человек
     * не должен молча исчезать из карточки из-за выбранной в шапке локации.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'source_lookup_id');
    }

    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'loss_reason_lookup_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DealItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * «Протухание» (deal rotting, приём HubSpot) — сделка лежит в текущей
     * стадии дольше норматива. Считается НА ЛЕТУ, фонового джоба намеренно
     * нет. Требует загруженной связи stage; для закрытых стадий и стадий без
     * норматива всегда false.
     */
    public function isRotting(): bool
    {
        $stage = $this->stage;

        if (! $stage || ! $stage->rotting_days || $stage->isClosing() || ! $this->stage_entered_at) {
            return false;
        }

        return $this->stage_entered_at->diffInDays(now()) > $stage->rotting_days;
    }

    /** Взвешенная сумма для прогноза: сумма × вероятность стадии. */
    public function weightedAmount(): int
    {
        $probability = $this->stage?->probability ?? 0;

        return (int) round($this->amount * $probability / 100);
    }
}
