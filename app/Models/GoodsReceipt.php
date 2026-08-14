<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'payment_status',
        'vat_calculation_method',
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
     * Оплата поставщику — тот же полиморфный payable, что и у WorkOrder/
     * Payroll (App\Services\FinanceService::processTransaction()), только
     * type='expense' (деньги уходят из кассы поставщику), а не 'income'.
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payable');
    }

    /**
     * Сгенерированные печатные формы (Фаза 12) — тот же полиморфный
     * documentable, что и у WorkOrder/Client, см. App\Models\Document.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Сумма закупки по накладной — точечно через ->append('total_value')
     * там, где items уже eager-loaded (см. GoodsReceiptController::show()),
     * иначе тихий N+1 (тот же приём, что у Document::isStale()). Единственная
     * осмысленная агрегация по позициям для KPI Карточки — сумма quantity
     * across позиций была бы бессмысленна (разные товары — разные единицы
     * измерения, "5 шт + 2 л").
     *
     * НДС: при vat_calculation_method='exclusive' цены позиций были БЕЗ
     * НДС — к сумме добавляется Σ(item.vat_amount), это и есть реальная
     * сумма к оплате поставщику. При 'inclusive' (или отсутствии НДС)
     * cost_price УЖЕ включает налог — формула не меняется, побайтово
     * совпадает с той, что была до появления НДС в системе.
     */
    protected function totalValue(): Attribute
    {
        return Attribute::make(
            get: function () {
                $sum = (int) $this->items->sum(fn (GoodsReceiptItem $item) => (int) round($item->quantity * $item->cost_price));

                if ($this->vat_calculation_method === 'exclusive') {
                    $sum += (int) $this->items->sum('vat_amount');
                }

                return $sum;
            },
        );
    }

    /**
     * Пересчитывает payment_status на основе суммы expense-транзакций
     * (payable_type=GoodsReceipt) — тот же паттерн, что WorkOrder::
     * syncPaymentStatus() для income. Вызывается FinanceService после
     * проведения/правки/отката транзакции (см. revertTransaction() —
     * method_exists($payable, 'syncPaymentStatus')), а также явно из
     * GoodsReceiptController::pay(). Требует загруженных items для
     * total_value — при отсутствии подгружает сам (та же защита от
     * N+1-ловушки, что у самого total_value, просто здесь цена запроса
     * оправдана: вызывается редко, не в списках).
     */
    public function syncPaymentStatus(): void
    {
        $this->loadMissing('items');

        $paidTotal = $this->transactions()->where('type', 'expense')->sum('amount');
        $total = $this->total_value;

        if ($total > 0 && $paidTotal >= $total) {
            $this->payment_status = 'paid';
        } elseif ($paidTotal > 0) {
            $this->payment_status = 'partial';
        } else {
            $this->payment_status = 'unpaid';
        }

        $this->save();
    }
}
