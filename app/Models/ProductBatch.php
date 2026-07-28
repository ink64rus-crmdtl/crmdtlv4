<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBatch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'batch_number',
        'initial_quantity',
        'current_quantity',
        'cost_price',
        'currency_id',
        'manufactured_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'initial_quantity' => 'decimal:3',
            'current_quantity' => 'decimal:3',
            'cost_price' => 'integer',
            'currency_id' => 'integer',
            'manufactured_at' => 'date',
            'expired_at' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}