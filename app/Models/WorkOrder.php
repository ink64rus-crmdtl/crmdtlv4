<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLog;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use HasActivityLog, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'legal_entity_id',
        'client_id',
        'vehicle_id',
        'status',
        'payment_status',
        'mileage',
        'total_amount',
        'discount_amount',
        'discount_is_manual',
        'final_amount',
        'currency_id',
        'created_by',
        'admin_assignment_mode',
        'vat_rate',
        'vat_calculation_method',
        'vat_amount',
    ];

    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
            'total_amount' => 'integer',
            'discount_amount' => 'integer',
            'discount_is_manual' => 'boolean',
            'final_amount' => 'integer',
            'currency_id' => 'integer',
            'vat_rate' => 'integer',
            'vat_amount' => 'integer',
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

    /**
     * Явный выбор юрлица для этого заказа — точка может иметь несколько
     * (branch_legal_entity), поэтому "чьё юрлицо" больше не выводится
     * однозначно из branch->legalEntity (см. DocumentPlaceholderService::
     * resolveLegalEntity()). Nullable — заказ может обойтись и без юрлица.
     */
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Администраторы заказа по умолчанию (для расчёта ЗП по каждой позиции,
     * если не переопределены на уровне позиции) — многие-ко-многим
     * (work_order_admins), у клиента может работать несколько администраторов
     * одновременно и делить начисление между собой (share_percent на пивоте).
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_order_admins')
            ->withPivot(['share_percent', 'manual_amount_override', 'manual_percent_override'])
            ->withTimestamps()
            // См. WorkOrderItem::employees() — назначенный человек не должен
            // исчезать из расчёта денег из-за выбранной в шапке локации или
            // последующего увольнения.
            ->withoutGlobalScope(BranchScope::class)
            ->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /**
     * Пересчитывает payment_status на основе суммы поступивших (income) транзакций.
     * O(1) относительно общего числа транзакций в системе — агрегат по индексу payable_type+payable_id.
     */
    public function syncPaymentStatus(): void
    {
        $paidTotal = $this->transactions()->where('type', 'income')->sum('amount');

        if ($this->final_amount > 0 && $paidTotal >= $this->final_amount) {
            $this->payment_status = 'paid';
        } elseif ($paidTotal > 0) {
            $this->payment_status = 'partial';
        } else {
            $this->payment_status = 'unpaid';
        }

        $this->save();
    }
}
