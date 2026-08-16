<?php

namespace Tests\Agent;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\WarehouseResolver;
use PHPUnit\Framework\Attributes\Test;

/**
 * WarehouseResolver::candidateWarehousesFor() — список складов для UI-фильтра
 * каталога товаров/материалов (CLAUDE.md «Фильтр каталога по остаткам»).
 * Список обязан совпадать с тем, между чем реально выбирает resolveFor() —
 * иначе фильтр предлагал бы выбор, который ни на что не влияет при списании.
 */
class WarehouseResolverCandidatesTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
        $this->otherBranch = Branch::create(['name' => 'Другая локация']);
    }

    #[Test]
    public function shared_mode_returns_no_candidates_at_all(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'shared']);
        Warehouse::create(['name' => 'Общий склад 1', 'owner_type' => 'company', 'owner_id' => null, 'is_default' => true, 'is_active' => true]);
        Warehouse::create(['name' => 'Общий склад 2', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);
        Warehouse::create(['name' => 'Склад локации', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_active' => true]);

        $candidates = WarehouseResolver::candidateWarehousesFor($this->branch);

        $this->assertCount(0, $candidates);
    }

    #[Test]
    public function mixed_mode_returns_company_warehouses_plus_this_branch_warehouses_only(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'mixed']);
        $company = Warehouse::create(['name' => 'Центральный склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);
        $mine = Warehouse::create(['name' => 'Склад моей локации', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_active' => true]);
        $foreign = Warehouse::create(['name' => 'Склад чужой локации', 'owner_type' => 'branch', 'owner_id' => $this->otherBranch->id, 'is_active' => true]);

        $candidateIds = WarehouseResolver::candidateWarehousesFor($this->branch)->pluck('id')->sort()->values()->all();

        $this->assertSame([$company->id, $mine->id], $candidateIds);
        $this->assertNotContains($foreign->id, $candidateIds);
    }

    #[Test]
    public function per_branch_mode_returns_only_this_branch_warehouses_via_pivot(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        Warehouse::create(['name' => 'Центральный склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);
        $mine = Warehouse::create(['name' => 'Склад моей локации', 'owner_type' => 'branch', 'owner_id' => null, 'is_active' => true]);
        $this->branch->warehouses()->attach($mine->id, ['priority' => 1]);
        $foreign = Warehouse::create(['name' => 'Склад чужой локации', 'owner_type' => 'branch', 'owner_id' => $this->otherBranch->id, 'is_active' => true]);

        $candidateIds = WarehouseResolver::candidateWarehousesFor($this->branch)->pluck('id')->all();

        $this->assertSame([$mine->id], $candidateIds);
        $this->assertNotContains($foreign->id, $candidateIds);
    }

    #[Test]
    public function per_branch_mode_falls_back_to_owner_id_match_without_pivot(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        // Без записи в branch_warehouse — тот же порядок фолбэка, что и в resolveFor().
        $mine = Warehouse::create(['name' => 'Склад моей локации', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_active' => true]);

        $candidateIds = WarehouseResolver::candidateWarehousesFor($this->branch)->pluck('id')->all();

        $this->assertSame([$mine->id], $candidateIds);
    }

    #[Test]
    public function inactive_warehouses_are_excluded_from_candidates(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'mixed']);
        Warehouse::create(['name' => 'Отключённый общий склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => false]);
        $active = Warehouse::create(['name' => 'Активный общий склад', 'owner_type' => 'company', 'owner_id' => null, 'is_active' => true]);

        $candidateIds = WarehouseResolver::candidateWarehousesFor($this->branch)->pluck('id')->all();

        $this->assertSame([$active->id], $candidateIds);
    }
}
