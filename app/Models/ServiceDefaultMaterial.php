<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Правило автодобавления материала при добавлении услуги в заказ
 * (CLAUDE.md, «Материалы на услугу»). НЕТ BranchScope — это настройка
 * каталога услуг (как ClientGroup/Lookup), не операционная запись под
 * локацией.
 */
class ServiceDefaultMaterial extends Model
{
    protected $fillable = [
        'service_id',
        'product_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
