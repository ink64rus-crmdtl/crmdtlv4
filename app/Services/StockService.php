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
     * вызывается из receiveGoods()/addReceiptItem()/updateReceiptItem() для
     * каждой позиции накладной. $goodsReceiptId/$batchNumber опциональны,
     * чтобы метод оставался пригоден для точечного оприходования без
     * накладной (если вдруг понадобится).
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
                self::createReceiptItem($receipt, $warehouse, $itemData, $userId);
            }

            return $receipt;
        });
    }

    /**
     * Добавление ОДНОЙ позиции к уже существующей (posted) накладной —
     * с Карточки, см. GoodsReceiptController::addItem(). Полноценная
     * альтернатива "отменить всю накладную и создать заново", если забыли
     * одну строку. Блокируется для уже отменённой накладной — добавлять
     * приход в то, чего по документам официально не было, не имеет смысла.
     *
     * @param  array{product_id:int,quantity:float,cost_price:int,batch_number:?string}  $itemData
     */
    public static function addReceiptItem(GoodsReceipt $receipt, array $itemData, ?int $userId = null): GoodsReceiptItem
    {
        if ($receipt->status !== 'posted') {
            throw new Exception('Нельзя добавить позицию в отменённую накладную.');
        }

        return DB::transaction(function () use ($receipt, $itemData, $userId) {
            $warehouse = Warehouse::findOrFail($receipt->warehouse_id);

            return self::createReceiptItem($receipt, $warehouse, $itemData, $userId);
        });
    }

    /**
     * Изменение количества/цены/партии уже оприходованной позиции — не
     * редактирование задним числом "как будто так и было", а честный
     * реверс старых движений/остатка/партии (та же защита, что у
     * removeReceiptItem — нельзя, если товар уже частично списан) с
     * последующим повторным оприходованием НОВЫМИ значениями. Позиция
     * (GoodsReceiptItem) остаётся той же строкой — история движений видит
     * оба факта (in_reversal старого и in нового) через тот же goods_receipt_id.
     *
     * @param  array{product_id:int,quantity:float,cost_price:int,batch_number:?string}  $newData
     */
    public static function updateReceiptItem(GoodsReceiptItem $item, array $newData, ?int $userId = null): GoodsReceiptItem
    {
        return DB::transaction(function () use ($item, $newData, $userId) {
            $item->loadMissing('goodsReceipt', 'product');
            $receipt = $item->goodsReceipt;

            if ($receipt->status !== 'posted') {
                throw new Exception('Нельзя изменить позицию отменённой накладной.');
            }

            self::reverseItemStockEffect($item, $receipt, $userId, "Корректировка позиции в накладной #{$receipt->id} (пересчёт)");

            $warehouse = Warehouse::findOrFail($receipt->warehouse_id);
            $product = Product::findOrFail($newData['product_id']);

            $batch = self::receipt(
                $product,
                $warehouse,
                $receipt->branch_id,
                $newData['quantity'],
                $newData['cost_price'],
                $userId,
                $receipt->id,
                $newData['batch_number'] ?? null,
            );

            $item->update([
                'product_id' => $product->id,
                'product_batch_id' => $batch?->id,
                'quantity' => $newData['quantity'],
                'cost_price' => $newData['cost_price'],
                'batch_number' => $newData['batch_number'] ?? null,
            ]);

            return $item->fresh();
        });
    }

    /**
     * Удаление ОДНОЙ позиции из накладной (не всей накладной — см.
     * reverseReceipt()) — реверс движения/остатка/партии этой позиции, сама
     * строка GoodsReceiptItem удаляется физически (в отличие от отмены всей
     * накладной, где позиции сохраняются как исторический факт статуса
     * 'canceled' — здесь позиции изначально не должно было быть, это правка
     * ошибки ввода, а не документированное событие).
     */
    public static function removeReceiptItem(GoodsReceiptItem $item, ?int $userId = null): void
    {
        DB::transaction(function () use ($item, $userId) {
            $item->loadMissing('goodsReceipt', 'product');
            $receipt = $item->goodsReceipt;

            if ($receipt->status !== 'posted') {
                throw new Exception('Нельзя удалить позицию отменённой накладной.');
            }

            self::reverseItemStockEffect($item, $receipt, $userId, "Удаление позиции из накладной #{$receipt->id}");

            $item->delete();
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
     *
     * В отличие от removeReceiptItem() строки GoodsReceiptItem НЕ удаляются —
     * это фиксация факта "накладная была, но отменена", а не правка ошибки
     * ввода: Карточка отменённой накладной должна по-прежнему показывать,
     * что именно в ней было.
     */
    public static function reverseReceipt(GoodsReceipt $receipt, ?int $userId = null): void
    {
        if ($receipt->status === 'canceled') {
            throw new Exception('Накладная уже отменена.');
        }

        DB::transaction(function () use ($receipt, $userId) {
            $receipt->loadMissing('items.product');

            foreach ($receipt->items as $item) {
                self::reverseItemStockEffect($item, $receipt, $userId, "Отмена прихода по накладной #{$receipt->id}");
            }

            $receipt->update(['status' => 'canceled']);
        });
    }

    /**
     * Общее ядро реверса ОДНОЙ позиции — используется и полной отменой
     * накладной (reverseReceipt), и точечным удалением/правкой позиции
     * (removeReceiptItem/updateReceiptItem). Сама GoodsReceiptItem НЕ
     * трогается здесь — только её эффект на остаток/партию плюс движение-
     * реверс для истории; что делать со строкой (удалить, оставить,
     * перезаписать) решает вызывающий метод.
     */
    private static function reverseItemStockEffect(GoodsReceiptItem $item, GoodsReceipt $receipt, ?int $userId, string $comment): void
    {
        $productName = is_array($item->product->name) ? ($item->product->name['ru'] ?? current($item->product->name)) : $item->product->name;

        $balance = StockBalance::where('warehouse_id', $receipt->warehouse_id)
            ->where('product_id', $item->product_id)
            ->first();

        if (! $balance || $balance->quantity < $item->quantity) {
            throw new Exception("Нельзя изменить/удалить позицию «{$productName}» — часть товара уже списана со склада.");
        }

        if ($item->product_batch_id) {
            $batch = ProductBatch::find($item->product_batch_id);
            if ($batch && $batch->current_quantity < $batch->initial_quantity) {
                throw new Exception("Нельзя изменить/удалить позицию «{$productName}» — партия уже частично списана.");
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
            'comment' => $comment,
        ]);

        $balance->quantity -= $item->quantity;
        $balance->save();

        if ($item->product_batch_id) {
            // Партия создана исключительно этой позицией и ещё не тронута
            // (проверка выше) — просто убираем её из учёта.
            ProductBatch::where('id', $item->product_batch_id)->delete();
        }
    }

    /**
     * @param  array{product_id:int,quantity:float,cost_price:int,batch_number:?string}  $itemData
     */
    private static function createReceiptItem(GoodsReceipt $receipt, Warehouse $warehouse, array $itemData, ?int $userId): GoodsReceiptItem
    {
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

        return GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_batch_id' => $batch?->id,
            'quantity' => $itemData['quantity'],
            'cost_price' => $itemData['cost_price'],
            'batch_number' => $itemData['batch_number'] ?? null,
        ]);
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
