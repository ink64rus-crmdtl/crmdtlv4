<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    protected $fillable = [
        'warehouse_id', 'product_id', 'quantity', 'avg_cost', 'currency_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'avg_cost' => 'integer',
            'currency_id' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
