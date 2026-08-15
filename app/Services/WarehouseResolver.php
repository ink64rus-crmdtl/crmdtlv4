<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Warehouse;

class WarehouseResolver
{
    /**
     * Глобальный тумблер складского учёта (Настройки → Склад). Выключен —
     * значит каталог товаров и их цены остаются, но приходные накладные,
     * остатки, движения и списание при завершении заказа не ведутся вовсе
     * (не просто скрыты — реально не создаются). См. EnsureWarehouseEnabled
     * (гейт роутов) и WorkOrderController::completeOrder() (гейт списания).
     */
    public static function isEnabled(): bool
    {
        return (Setting::where('key', 'warehouse_enabled')->value('value') ?? '1') === '1';
    }

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
