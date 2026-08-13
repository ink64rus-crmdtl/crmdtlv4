<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'warehouse_id',
        'branch_id',
        'product_id',
        'product_batch_id',
        'work_order_id',
        'type',
        'quantity',
        'cost_price',
        'currency_id',
        'comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'cost_price' => 'integer',
            'currency_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
