<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\StockMovement;
use App\Models\StockBalance;
use App\Models\ProductBatch;
use Exception;

class StockService
{
    /**
     * Оприходование товара на склад (Поступление).
     */
    public static function receipt(Product $product, Warehouse $warehouse, int $branchId, float $quantity, int $costPriceCents, ?int $userId = null): void
    {
        $batchId = null;

        // Если учет партионный (FIFO), создаем новую партию
        if ($product->accounting_type === 'batch') {
            $batch = ProductBatch::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'cost_price' => $costPriceCents,
            ]);
            $batchId = $batch->id;
        }

        // Фиксируем движение (Приход)
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'product_batch_id' => $batchId,
            'type' => 'in',
            'quantity' => $quantity,
            'cost_price' => $costPriceCents,
            'created_by' => $userId,
            'comment' => 'Оприходование товара',
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
            'comment' => $workOrderId ? "Списание по заказ-наряду #{$workOrderId}" : "Ручное списание",
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
            if ($remaining <= 0) break;

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