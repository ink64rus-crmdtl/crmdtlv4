<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\Warehouse;

class WarehouseResolver
{
    /**
     * Определяет, с какого склада нужно списать товар в зависимости от настроек тенанта.
     */
    public static function resolveFor(Product $product, Branch $branch): ?Warehouse
    {
        $mode = Setting::where('key', 'warehouse_mode')->value('value') ?? 'per_branch';

        // Смешанный режим: если у товара жестко задан склад (например, центральный для дорогих пленок)
        if ($mode === 'mixed' && $product->preferred_warehouse_id) {
            return Warehouse::find($product->preferred_warehouse_id);
        }

        // Общий режим: ищем дефолтный склад компании
        if ($mode === 'shared') {
            return Warehouse::where('owner_type', 'company')->where('is_default', true)->first()
                ?? Warehouse::where('owner_type', 'company')->first();
        }

        // Раздельный режим (или смешанный без preferred_warehouse_id): ищем склад точки
        // Сначала проверяем связь через pivot (если настроены приоритеты)
        $branchWarehouse = $branch->warehouses()->orderBy('branch_warehouse.priority')->first();
        if ($branchWarehouse) {
            return $branchWarehouse;
        }

        // Фолбэк: ищем склад, где owner_id = branch_id
        return Warehouse::where('owner_type', 'branch')->where('owner_id', $branch->id)->first();
    }
}