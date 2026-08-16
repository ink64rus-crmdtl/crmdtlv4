<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\BranchController;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

/**
 * BranchController::store() — автосоздание склада точки для новой локации
 * (CLAUDE.md, "Настройка складов и режимов"): самый частый в жизни сценарий
 * дрейфа конфигурации — завели вторую локацию в режиме per_branch/mixed,
 * забыли завести под неё склад. Теперь склад заводится сам, если он реально
 * понадобится резолверу; в остальных случаях — не создаётся зря.
 */
class BranchWarehouseAutoProvisionTest extends TenantAgentTestCase
{
    private function createBranch(string $name = 'Новая локация'): Branch
    {
        $request = new Request;
        $request->merge(['name' => $name]);
        app(BranchController::class)->store($request);

        return Branch::where('name', $name)->firstOrFail();
    }

    #[Test]
    public function per_branch_mode_auto_creates_active_default_warehouse_for_new_branch(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);

        $branch = $this->createBranch();

        $warehouse = Warehouse::where('owner_type', 'branch')->where('owner_id', $branch->id)->first();
        $this->assertNotNull($warehouse);
        $this->assertTrue($warehouse->is_active);
        $this->assertTrue($warehouse->is_default);
    }

    #[Test]
    public function mixed_mode_also_auto_creates_branch_warehouse(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'mixed']);

        $branch = $this->createBranch();

        $this->assertTrue(Warehouse::where('owner_type', 'branch')->where('owner_id', $branch->id)->exists());
    }

    #[Test]
    public function shared_mode_does_not_auto_create_a_warehouse(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'shared']);

        $branch = $this->createBranch();

        $this->assertFalse(Warehouse::where('owner_type', 'branch')->where('owner_id', $branch->id)->exists());
    }

    #[Test]
    public function disabled_warehouse_tracking_does_not_auto_create_a_warehouse(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        Setting::updateOrCreate(['key' => 'warehouse_enabled'], ['value' => '0']);

        $branch = $this->createBranch();

        $this->assertFalse(Warehouse::where('owner_type', 'branch')->where('owner_id', $branch->id)->exists());
    }
}
