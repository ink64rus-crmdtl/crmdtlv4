<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

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
     * Деактивированный склад НИКОГДА не возвращается — ни явно заданный preferred_warehouse_id
     * товара, ни дефолтный/первый попавшийся кандидат: списание в неактивный склад означало бы
     * молчаливое движение по складу, который админ намеренно вывел из оборота.
     */
    public static function resolveFor(Product $product, Branch $branch): ?Warehouse
    {
        $mode = Setting::where('key', 'warehouse_mode')->value('value') ?? 'per_branch';

        // Смешанный режим: если у товара жёстко задан склад (например, центральный для дорогих
        // плёнок) и он ещё активен — используем его. Если деактивирован — НЕ возвращаем null
        // сразу, а падаем ниже на резолюцию склада точки, как будто preferred не задан вовсе.
        if ($mode === 'mixed' && $product->preferred_warehouse_id) {
            $preferred = Warehouse::where('id', $product->preferred_warehouse_id)->where('is_active', true)->first();
            if ($preferred) {
                return $preferred;
            }
        }

        // Общий режим: ищем дефолтный активный склад компании, иначе — любой активный
        if ($mode === 'shared') {
            return Warehouse::where('owner_type', 'company')->where('is_active', true)->where('is_default', true)->first()
                ?? Warehouse::where('owner_type', 'company')->where('is_active', true)->first();
        }

        // Раздельный режим (или смешанный без активного preferred_warehouse_id): ищем склад точки
        // Сначала проверяем связь через pivot (если настроены приоритеты)
        $branchWarehouse = $branch->warehouses()->where('is_active', true)->orderBy('branch_warehouse.priority')->first();
        if ($branchWarehouse) {
            return $branchWarehouse;
        }

        // Фолбэк: ищем склад, где owner_id = branch_id
        return Warehouse::where('owner_type', 'branch')->where('owner_id', $branch->id)->where('is_active', true)->first();
    }

    /**
     * Склады, из которых логически МОЖЕТ произойти списание для этой локации —
     * используется UI-фильтром каталога товаров/материалов при их добавлении
     * в заказ (CLAUDE.md «Фильтр каталога по остаткам»), чтобы список складов
     * для выбора не противоречил тому, что реально решит resolveFor():
     * - shared: список ПУСТ — списание всегда с одного и того же дефолтного
     *   склада компании, выбор в фильтре ничего не меняет и только запутывал бы;
     * - mixed: общие (owner_type=company) склады + склады этой локации — ровно
     *   те два источника, между которыми выбирает resolveFor() в этом режиме;
     * - per_branch: только склады этой локации (через pivot branch_warehouse,
     *   с фолбэком на owner_type=branch/owner_id=branch.id — тот же порядок,
     *   что и в resolveFor()).
     */
    public static function candidateWarehousesFor(Branch $branch): Collection
    {
        $mode = Setting::where('key', 'warehouse_mode')->value('value') ?? 'per_branch';

        if ($mode === 'shared') {
            return collect();
        }

        $branchWarehouses = $branch->warehouses()->where('is_active', true)->orderBy('branch_warehouse.priority')->get();
        if ($branchWarehouses->isEmpty()) {
            $branchWarehouses = Warehouse::where('owner_type', 'branch')->where('owner_id', $branch->id)->where('is_active', true)->get();
        }

        if ($mode === 'mixed') {
            $companyWarehouses = Warehouse::where('owner_type', 'company')->where('is_active', true)->get();

            return $companyWarehouses->merge($branchWarehouses)->unique('id')->values();
        }

        return $branchWarehouses->values();
    }

    /**
     * Активные локации, у которых сейчас НЕТ ни одного активного склада точки
     * (ни через branch_warehouse, ни через owner_type=branch/owner_id) — то есть
     * для которых resolveFor() в режиме per_branch/mixed (без активного
     * preferred_warehouse_id у товара) вернёт null и завершение заказа со
     * списанием упадёт с исключением. Используется и валидацией смены режима
     * (WarehouseSettingsController::store()), и диагностическим баннером на
     * странице настроек, и проверкой при деактивации/удалении конкретного
     * склада — во всех трёх местах нужен ОДИН и тот же источник истины.
     *
     * $excludeWarehouseId — исключить конкретный склад из подсчёта покрытия,
     * чтобы проверить "останется ли локация без склада, если ЭТОТ конкретный
     * убрать" (деактивация/удаление ДО того, как изменение реально применено).
     */
    public static function branchesWithoutWarehouse(?int $excludeWarehouseId = null): Collection
    {
        return Branch::where('is_active', true)->get(['id', 'name'])->reject(function (Branch $branch) use ($excludeWarehouseId) {
            $viaPivot = $branch->warehouses()
                ->where('is_active', true)
                ->when($excludeWarehouseId, fn ($q) => $q->where('warehouses.id', '!=', $excludeWarehouseId))
                ->exists();

            if ($viaPivot) {
                return true;
            }

            return Warehouse::where('owner_type', 'branch')
                ->where('owner_id', $branch->id)
                ->where('is_active', true)
                ->when($excludeWarehouseId, fn ($q) => $q->where('id', '!=', $excludeWarehouseId))
                ->exists();
        })->values();
    }

    /**
     * Есть ли хотя бы один активный общий (owner_type=company) склад — без него
     * режим 'shared' не резолвит НИ ОДНОЙ локации. $excludeWarehouseId — та же
     * логика "проверить, останется ли покрытие без ЭТОГО конкретного склада",
     * что и у branchesWithoutWarehouse().
     */
    public static function hasActiveCompanyWarehouse(?int $excludeWarehouseId = null): bool
    {
        return Warehouse::where('owner_type', 'company')
            ->where('is_active', true)
            ->when($excludeWarehouseId, fn ($q) => $q->where('id', '!=', $excludeWarehouseId))
            ->exists();
    }
}
