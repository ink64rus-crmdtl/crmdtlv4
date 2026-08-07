<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id',
        'employee_id',
        'itemable_type',
        'itemable_id',
        'name',
        'quantity',
        'price',
        'discount_amount',
        'total',
        'currency_id',
        'sort_order',
        'admin_override',
        'admin_employee_id',
        'linked_item_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'quantity' => 'decimal:3',
            'price' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'currency_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_order_item_employees')
            ->withTimestamps()
            ->withPivot(['share_percent', 'manual_amount_override', 'manual_percent_override']);
    }

    public function adminEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'admin_employee_id');
    }

    /**
     * Позиция-услуга, к которой привязана эта позиция-материал (для вычета
     * стоимости материалов из базы расчёта ЗП именно этой услуги).
     */
    public function linkedItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class, 'linked_item_id');
    }

    /**
     * Обратная связь: материалы, привязанные к этой позиции-услуге.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'linked_item_id');
    }
}