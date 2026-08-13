<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Exception;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Оприходование одной позиции товара на склад — низкоуровневый примитив,
     * вызывается из receiveGoods() для каждой позиции накладной. $goodsReceiptId/
     * $batchNumber опциональны, чтобы метод оставался пригоден для точечного
     * оприходования без накладной (если вдруг понадобится).
     */
    public static function receipt(Product $product, Warehouse $warehouse, int $branchId, float $quantity, int $costPriceCents, ?int $userId = null, ?int $goodsReceiptId = null, ?string $batchNumber = null): ?ProductBatch
    {
        $batch = null;

        // Если учет партионный (FIFO), создаем новую партию
        if ($product->accounting_type === 'batch') {
            $batch = ProductBatch::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'batch_number' => $batchNumber,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'cost_price' => $costPriceCents,
            ]);
        }

        // Фиксируем движение (Приход)
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'product_batch_id' => $batch?->id,
            'goods_receipt_id' => $goodsReceiptId,
            'type' => 'in',
            'quantity' => $quantity,
            'cost_price' => $costPriceCents,
            'created_by' => $userId,
            'comment' => $goodsReceiptId ? "Оприходование по накладной #{$goodsReceiptId}" : 'Оприходование товара',
        ]);

        // Обновляем или создаем запись текущего остатка
        $balance = StockBalance::firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity' => 0, 'avg_cost' => 0]
        );

        // Если учет средневзвешенный, пересчитываем среднюю цену
        if ($product->accounting_type === 'average') {
            $currentTotalValue = $balance->quantity * $balance->avg_cost;
            $newTotalValue = $currentTotalValue + ($quantity * $costPriceCents);
            $newQuantity = $balance->quantity + $quantity;

            $balance->avg_cost = $newQuantity > 0 ? (int) round($newTotalValue / $newQuantity) : 0;
        }

        $balance->quantity += $quantity;
        $balance->save();

        return $batch;
    }

    /**
     * Оприходование ПОСТАВКИ — накладная (шапка) + позиции одной транзакцией.
     * Заменяет прежний цикл "по одному receipt() из контроллера" — теперь
     * позиции объединены под одной GoodsReceipt и прослеживаются через
     * StockMovement.goods_receipt_id (см. GoodsReceiptController::store()).
     *
     * @param  array{supplier_id:int, warehouse_id:int, branch_id:int, legal_entity_id:?int, receipt_date:string, supplier_document_number:?string, comment:?string, items:array<int,array{product_id:int,quantity:float,cost_price:int,batch_number:?string}>}  $data
     */
    public static function receiveGoods(array $data, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $userId) {
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);

            $receipt = GoodsReceipt::create([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $warehouse->id,
                'branch_id' => $data['branch_id'],
                'legal_entity_id' => $data['legal_entity_id'] ?? null,
                'receipt_date' => $data['receipt_date'],
                'supplier_document_number' => $data['supplier_document_number'] ?? null,
                'comment' => $data['comment'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                $batch = self::receipt(
                    $product,
                    $warehouse,
                    $receipt->branch_id,
                    $itemData['quantity'],
                    $itemData['cost_price'],
                    $userId,
                    $receipt->id,
                    $itemData['batch_number'] ?? null,
                );

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'product_id' => $product->id,
                    'product_batch_id' => $batch?->id,
                    'quantity' => $itemData['quantity'],
                    'cost_price' => $itemData['cost_price'],
                    'batch_number' => $itemData['batch_number'] ?? null,
                ]);
            }

            return $receipt;
        });
    }

    /**
     * Отмена приходной накладной — реверс движений/остатков/партий (не
     * удаление записи, GoodsReceipt.status → 'canceled'). Блокируется, если
     * товар с этой поставки уже частично списан — иначе остаток ушёл бы в
     * минус молча. Для средневзвешенного учёта avg_cost НЕ откатывается
     * назад (это лишь приближение: если после этого прихода были другие
     * поступления/списания, точный откат средней цены математически
     * невозможен без полной переигровки истории) — откатывается только
     * количество, что для сценария "оприходовали по ошибке, тут же отменили"
     * достаточно и не искажает будущие расчёты сильнее, чем сама ошибка.
     */
    public static function reverseReceipt(GoodsReceipt $receipt, ?int $userId = null): void
    {
        if ($receipt->status === 'canceled') {
            throw new Exception('Накладная уже отменена.');
        }

        DB::transaction(function () use ($receipt, $userId) {
            $receipt->loadMissing('items.product');

            foreach ($receipt->items as $item) {
                $productName = is_array($item->product->name) ? ($item->product->name['ru'] ?? current($item->product->name)) : $item->product->name;

                $balance = StockBalance::where('warehouse_id', $receipt->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if (! $balance || $balance->quantity < $item->quantity) {
                    throw new Exception("Нельзя отменить приход «{$productName}» — часть товара уже списана со склада.");
                }

                if ($item->product_batch_id) {
                    $batch = ProductBatch::find($item->product_batch_id);
                    if ($batch && $batch->current_quantity < $batch->initial_quantity) {
                        throw new Exception("Нельзя отменить приход «{$productName}» — партия уже частично списана.");
                    }
                }

                StockMovement::create([
                    'warehouse_id' => $receipt->warehouse_id,
                    'branch_id' => $receipt->branch_id,
                    'product_id' => $item->product_id,
                    'product_batch_id' => $item->product_batch_id,
                    'goods_receipt_id' => $receipt->id,
                    'type' => 'in_reversal',
                    'quantity' => $item->quantity,
                    'cost_price' => $item->cost_price,
                    'created_by' => $userId,
                    'comment' => "Отмена прихода по накладной #{$receipt->id}",
                ]);

                $balance->quantity -= $item->quantity;
                $balance->save();

                if ($item->product_batch_id) {
                    // Партия создана исключительно этим приходом и ещё не
                    // тронута (проверка выше) — просто убираем её из учёта.
                    ProductBatch::where('id', $item->product_batch_id)->delete();
                }
            }

            $receipt->update(['status' => 'canceled']);
        });
    }

    /**
     * Списывает товар со склада с учетом типа учета (Средневзвешенный или Партионный/FIFO).
     */
    public static function deduct(Product $product, Warehouse $warehouse, int $branchId, float $quantity, ?int $workOrderId = null, ?int $userId = null): void
    {
        if ($product->accounting_type === 'batch') {
            self::deductFIFO($product, $warehouse, $branchId, $quantity, $workOrderId, $userId);
        } else {
            self::deductAverage($product, $warehouse, $branchId, $quantity, $workOrderId, $userId);
        }
    }

    private static function deductAverage(Product $product, Warehouse $warehouse, int $branchId, float $quantity, ?int $workOrderId, ?int $userId): void
    {
        $balance = StockBalance::firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity' => 0, 'avg_cost' => 0]
        );

        if ($balance->quantity < $quantity) {
            $productName = is_array($product->name) ? ($product->name['ru'] ?? current($product->name)) : $product->name;
            throw new Exception("Недостаточно остатков для товара: {$productName}. В наличии: {$balance->quantity} {$product->unit}");
        }

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'work_order_id' => $workOrderId,
            'type' => 'out',
            'quantity' => $quantity,
            'cost_price' => $balance->avg_cost,
            'created_by' => $userId,
            'comment' => $workOrderId ? "Списание по заказ-наряду #{$workOrderId}" : 'Ручное списание',
        ]);

        $balance->quantity -= $quantity;
        $balance->save();
    }

    private static function deductFIFO(Product $product, Warehouse $warehouse, int $branchId, float $quantity, ?int $workOrderId, ?int $userId): void
    {
        $batches = ProductBatch::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('current_quantity', '>', 0)
            ->orderBy('created_at', 'asc') // FIFO
            ->get();

        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($batch->current_quantity, $remaining);

            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_batch_id' => $batch->id,
                'work_order_id' => $workOrderId,
                'type' => 'out',
                'quantity' => $take,
                'cost_price' => $batch->cost_price,
                'created_by' => $userId,
                'comment' => $workOrderId ? "Списание по заказ-наряду #{$workOrderId} (Партия #{$batch->id})" : "Ручное списание (Партия #{$batch->id})",
            ]);

            $batch->current_quantity -= $take;
            $batch->save();

            $remaining -= $take;
        }

        if ($remaining > 0) {
            $productName = is_array($product->name) ? ($product->name['ru'] ?? current($product->name)) : $product->name;
            throw new Exception("Недостаточно остатков в партиях для товара: {$productName}. Не хватает: {$remaining} {$product->unit}");
        }

        // Обновляем общий баланс для быстрого доступа
        $balance = StockBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        if ($balance) {
            $balance->quantity -= $quantity;
            $balance->save();
        }
    }
}
