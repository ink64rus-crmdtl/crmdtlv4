<?php

namespace Tests\Agent;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\WarehouseResolver;
use PHPUnit\Framework\Attributes\Test;

/**
 * WarehouseResolver::resolveFor() — деактивированный склад никогда не должен
 * стать местом списания (живой баг, найденный на реальном тенанте: не
 * проверялся is_active нигде в методе). WarehouseResolver::branchesWithoutWarehouse()/
 * hasActiveCompanyWarehouse() — источник истины для валидации смены режима
 * и диагностического баннера в WarehouseSettingsController (CLAUDE.md,
 * "Настройка складов и режимов").
 */
class WarehouseResolverCoverageTest extends TenantAgentTestCase
{
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
    }

    #[Test]
    public function mixed_mode_falls_back_to_branch_warehouse_when_preferred_is_inactive(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'mixed']);
        $inactivePreferred = Warehouse::create(['name' => 'Деактивированный предпочитаемый', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => false]);
        $branchWarehouse = Warehouse::create(['name' => 'Склад локации', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_active' => true]);
        $product = Product::create(['name' => 'Плёнка', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true, 'preferred_warehouse_id' => $inactivePreferred->id]);

        $resolved = WarehouseResolver::resolveFor($product, $this->branch);

        $this->assertSame($branchWarehouse->id, $resolved->id);
    }

    #[Test]
    public function shared_mode_skips_inactive_default_and_falls_back_to_active_company_warehouse(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'shared']);
        Warehouse::create(['name' => 'Деактивированный дефолтный', 'owner_type' => 'company', 'owner_id' => null, 'is_default' => true, 'is_active' => false]);
        $active = Warehouse::create(['name' => 'Активный общий', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);
        $product = Product::create(['name' => 'Химия', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);

        $resolved = WarehouseResolver::resolveFor($product, $this->branch);

        $this->assertSame($active->id, $resolved->id);
    }

    #[Test]
    public function per_branch_mode_ignores_inactive_branch_warehouse(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        Warehouse::create(['name' => 'Деактивированный склад локации', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_active' => false]);
        $product = Product::create(['name' => 'Химия', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);

        $resolved = WarehouseResolver::resolveFor($product, $this->branch);

        $this->assertNull($resolved);
    }

    #[Test]
    public function branches_without_warehouse_lists_branches_missing_active_branch_warehouse(): void
    {
        $covered = Branch::create(['name' => 'С складом']);
        Warehouse::create(['name' => 'Склад', 'owner_type' => 'branch', 'owner_id' => $covered->id, 'is_active' => true]);
        $uncovered = Branch::create(['name' => 'Без склада']);

        $missing = WarehouseResolver::branchesWithoutWarehouse()->pluck('id')->all();

        $this->assertContains($uncovered->id, $missing);
        $this->assertNotContains($covered->id, $missing);
    }

    #[Test]
    public function branches_without_warehouse_excludes_given_warehouse_id_from_coverage(): void
    {
        $warehouse = Warehouse::create(['name' => 'Единственный склад локации', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_active' => true]);

        $this->assertFalse(WarehouseResolver::branchesWithoutWarehouse()->contains('id', $this->branch->id));
        $this->assertTrue(WarehouseResolver::branchesWithoutWarehouse($warehouse->id)->contains('id', $this->branch->id));
    }

    #[Test]
    public function has_active_company_warehouse_respects_exclusion(): void
    {
        $warehouse = Warehouse::create(['name' => 'Единственный общий склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);

        $this->assertTrue(WarehouseResolver::hasActiveCompanyWarehouse());
        $this->assertFalse(WarehouseResolver::hasActiveCompanyWarehouse($warehouse->id));
    }
}
