<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = [
        'product_category_id',
        'name',
        'sku',
        'unit',
        'accounting_type',
        'preferred_warehouse_id',
        'is_active',
        'base_price',
        'markup_percent',
        'discount_percent',
        'affects_payroll_by_default',
    ];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'base_price' => 'integer',
            'markup_percent' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'affects_payroll_by_default' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function preferredWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'preferred_warehouse_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
