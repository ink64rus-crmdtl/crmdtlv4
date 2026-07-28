<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    protected $fillable = [
        'warehouse_id', 'product_id', 'quantity', 'avg_cost', 'currency_id'
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'avg_cost' => 'integer',
            'currency_id' => 'integer',
        ];
    }
}