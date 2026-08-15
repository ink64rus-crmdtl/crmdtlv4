<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Позиция предварительной сметы сделки — смысловая копия AppointmentItem.
 * Существует только ради коммерческого предложения: склад не резервирует,
 * в финансы не попадает. Реальные деньги начинаются с WorkOrderItem.
 */
class DealItem extends Model
{
    protected $fillable = [
        'deal_id',
        'itemable_type',
        'itemable_id',
        'name',
        'quantity',
        'price',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'price' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Сумма позиции. Скидок на уровне сметы нет — это предварительный расчёт. */
    public function total(): int
    {
        return (int) round($this->quantity * $this->price);
    }
}
