<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Приходная накладная — шапка поставки от поставщика (Client с ролью
 * «Поставщик», см. GoodsReceiptController::SUPPLIER_ROLE). Позиции —
 * goods_receipt_items, каждая создаёт StockMovement + (для партионного
 * учёта) ProductBatch через StockService::receiptFor().
 */
class GoodsReceipt extends Model
{
    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'branch_id',
        'legal_entity_id',
        'receipt_date',
        'supplier_document_number',
        'status',
        'comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'supplier_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Сумма закупки по накладной — точечно через ->append('total_value')
     * там, где items уже eager-loaded (см. GoodsReceiptController::show()),
     * иначе тихий N+1 (тот же приём, что у Document::isStale()). Единственная
     * осмысленная агрегация по позициям для KPI Карточки — сумма quantity
     * across позиций была бы бессмысленна (разные товары — разные единицы
     * измерения, "5 шт + 2 л").
     */
    protected function totalValue(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->items->sum(fn (GoodsReceiptItem $item) => (int) round($item->quantity * $item->cost_price)),
        );
    }
}
