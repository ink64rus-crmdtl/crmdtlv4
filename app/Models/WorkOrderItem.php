<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'total',
        'currency_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'quantity' => 'decimal:3',
            'price' => 'integer',
            'total' => 'integer',
            'currency_id' => 'integer',
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
}