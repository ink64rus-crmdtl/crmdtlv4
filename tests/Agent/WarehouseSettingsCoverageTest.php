<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\WarehouseSettingsController;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

/**
 * WarehouseSettingsController — валидация согласованности warehouse_mode со
 * складами (CLAUDE.md, "Настройка складов и режимов"): живой баг на реальном
 * тенанте (per_branch без единого склада точки → resolveFor() возвращал null,
 * завершение заказа падало) теперь не удаётся сохранить/довести до этого
 * состояния вовсе — ни сменой режима, ни деактивацией/удалением последнего
 * подходящего склада.
 */
class WarehouseSettingsCoverageTest extends TenantAgentTestCase
{
    private function controller(): WarehouseSettingsController
    {
        return app(WarehouseSettingsController::class);
    }

    private function store(array $data)
    {
        $request = new Request;
        $request->merge(array_merge([
            'warehouse_mode' => 'per_branch',
            'warehouse_enabled' => true,
            'service_material_auto_add_mode' => 'confirm',
        ], $data));

        return $this->controller()->store($request);
    }

    #[Test]
    public function store_blocks_per_branch_mode_when_a_branch_has_no_warehouse(): void
    {
        Branch::create(['name' => 'Без склада']);

        $response = $this->store(['warehouse_mode' => 'per_branch']);

        $this->assertNotSame('per_branch', Setting::where('key', 'warehouse_mode')->value('value'));
        $this->assertNotEmpty($response->getSession()->get('errors')?->get('warehouse_mode'));
    }

    #[Test]
    public function store_allows_per_branch_mode_when_every_branch_has_a_warehouse(): void
    {
        $branch = Branch::create(['name' => 'Со складом']);
        Warehouse::create(['name' => 'Склад локации', 'owner_type' => 'branch', 'owner_id' => $branch->id, 'is_active' => true]);

        $this->store(['warehouse_mode' => 'per_branch']);

        $this->assertSame('per_branch', Setting::where('key', 'warehouse_mode')->value('value'));
    }

    #[Test]
    public function store_blocks_shared_mode_without_active_company_warehouse(): void
    {
        $response = $this->store(['warehouse_mode' => 'shared']);

        $this->assertNotSame('shared', Setting::where('key', 'warehouse_mode')->value('value'));
        $this->assertNotEmpty($response->getSession()->get('errors')?->get('warehouse_mode'));
    }

    #[Test]
    public function store_allows_shared_mode_with_active_company_warehouse(): void
    {
        Warehouse::create(['name' => 'Общий склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);

        $this->store(['warehouse_mode' => 'shared']);

        $this->assertSame('shared', Setting::where('key', 'warehouse_mode')->value('value'));
    }

    #[Test]
    public function store_skips_coverage_validation_when_warehouse_tracking_disabled(): void
    {
        // Ни одной локации со складом нет вовсе, но склад выключен целиком —
        // режим сейчас ни на что не влияет, блокировать сохранение незачем.
        Branch::create(['name' => 'Без склада']);

        $this->store(['warehouse_mode' => 'per_branch', 'warehouse_enabled' => false]);

        $this->assertSame('per_branch', Setting::where('key', 'warehouse_mode')->value('value'));
        $this->assertSame('0', Setting::where('key', 'warehouse_enabled')->value('value'));
    }

    #[Test]
    public function update_warehouse_blocks_deactivating_the_last_active_branch_warehouse_in_per_branch_mode(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        $branch = Branch::create(['name' => 'Локация']);
        $warehouse = Warehouse::create(['name' => 'Единственный склад локации', 'owner_type' => 'branch', 'owner_id' => $branch->id, 'is_active' => true]);

        $request = new Request;
        $request->merge(['name' => $warehouse->name, 'owner_type' => 'branch', 'owner_id' => $branch->id, 'is_active' => false]);
        $response = $this->controller()->updateWarehouse($request, $warehouse->fresh());

        $this->assertTrue($warehouse->fresh()->is_active, 'Деактивация должна быть заблокирована.');
        $this->assertNotEmpty($response->getSession()->get('errors')?->get('error'));
    }

    #[Test]
    public function update_warehouse_allows_deactivating_when_branch_still_covered_by_another_warehouse(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        $branch = Branch::create(['name' => 'Локация']);
        $warehouse = Warehouse::create(['name' => 'Первый склад', 'owner_type' => 'branch', 'owner_id' => $branch->id, 'is_active' => true]);
        $branch->warehouses()->attach(
            Warehouse::create(['name' => 'Второй склад', 'owner_type' => 'branch', 'owner_id' => null, 'is_active' => true])->id,
            ['priority' => 1]
        );

        $request = new Request;
        $request->merge(['name' => $warehouse->name, 'owner_type' => 'branch', 'owner_id' => $branch->id, 'is_active' => false]);
        $this->controller()->updateWarehouse($request, $warehouse->fresh());

        $this->assertFalse($warehouse->fresh()->is_active);
    }

    #[Test]
    public function destroy_warehouse_blocks_deleting_the_last_active_company_warehouse_in_shared_mode(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'shared']);
        $warehouse = Warehouse::create(['name' => 'Единственный общий склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);

        $response = $this->controller()->destroyWarehouse($warehouse);

        $this->assertNotNull(Warehouse::find($warehouse->id), 'Удаление должно быть заблокировано.');
        $this->assertNotEmpty($response->getSession()->get('errors')?->get('error'));
    }

    #[Test]
    public function destroy_warehouse_allows_deleting_company_warehouse_irrelevant_to_current_mode(): void
    {
        // per_branch режим не смотрит на company-склады вообще — удалять его
        // здесь безопасно, проверка не должна срабатывать для чужого owner_type.
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        $warehouse = Warehouse::create(['name' => 'Ненужный общий склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);

        $this->controller()->destroyWarehouse($warehouse);

        $this->assertNull(Warehouse::find($warehouse->id));
    }
}
