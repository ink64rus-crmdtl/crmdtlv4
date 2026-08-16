<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\WorkOrderController;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

/**
 * Списание материала на услугу при завершении заказа (CLAUDE.md «Материалы
 * на услугу») — WorkOrderController::completeOrder() и новый параметр
 * $allowNegative у StockService::deduct(). Складской учёт до этой фичи
 * тестами покрыт не был вовсе — эти тесты закрывают и регрессию (дефолтное
 * поведение для обычных товаров не ослаблено), и новую возможность.
 */
class StockMaterialDeductionTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Warehouse $warehouse;

    private WorkOrder $workOrder;

    private WorkOrderItem $serviceItem;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'warehouse_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
        $this->warehouse = Warehouse::create([
            'name' => 'Тестовый склад',
            'owner_type' => 'branch',
            'owner_id' => $this->branch->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        $category = ServiceCategory::create(['name' => 'Тестовая группа', 'is_active' => true]);
        $service = Service::create([
            'service_category_id' => $category->id,
            'name' => 'Тестовая услуга',
            'price' => 100000,
            'is_active' => true,
        ]);

        $client = Client::create(['branch_id' => $this->branch->id, 'type' => 'b2c', 'name' => 'Заказчик']);

        $this->workOrder = WorkOrder::create([
            'branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'status' => 'in_progress',
            'payment_status' => 'unpaid',
            'total_amount' => 100000,
            'discount_amount' => 0,
            'final_amount' => 100000,
            'admin_assignment_mode' => 'manual',
        ]);

        $this->serviceItem = WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Service::class,
            'itemable_id' => $service->id,
            'name' => 'Тестовая услуга',
            'quantity' => 1,
            'price' => 100000,
            'discount_amount' => 0,
            'total' => 100000,
        ]);
    }

    private function makeMaterialItem(Product $product, float $quantity, array $overrides = []): WorkOrderItem
    {
        return WorkOrderItem::create(array_merge([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Product::class,
            'itemable_id' => $product->id,
            'linked_item_id' => $this->serviceItem->id,
            'name' => is_array($product->name) ? ($product->name['ru'] ?? current($product->name)) : $product->name,
            'quantity' => $quantity,
            'price' => 5000,
            'discount_amount' => 0,
            'total' => (int) round($quantity * 5000),
            'is_billable' => false,
            'affects_payroll' => true,
        ], $overrides));
    }

    private function completeOrder(): void
    {
        app(WorkOrderController::class)->completeOrder(new Request, $this->workOrder->fresh());
    }

    #[Test]
    public function allow_negative_stock_lets_completion_succeed_despite_insufficient_balance(): void
    {
        $product = Product::create(['name' => 'Полироль', 'unit' => 'мл', 'accounting_type' => 'average', 'is_active' => true]);
        StockBalance::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'quantity' => 5, 'avg_cost' => 1000]);

        $this->makeMaterialItem($product, 20, ['allow_negative_stock' => true]);

        $this->completeOrder();

        $this->assertSame('completed', $this->workOrder->fresh()->status);
        $balance = StockBalance::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame('-15.000', (string) $balance->quantity);
    }

    #[Test]
    public function insufficient_balance_without_override_still_blocks_completion_as_before(): void
    {
        $product = Product::create(['name' => 'Полироль', 'unit' => 'мл', 'accounting_type' => 'average', 'is_active' => true]);
        StockBalance::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'quantity' => 5, 'avg_cost' => 1000]);

        // allow_negative_stock не задан — дефолт false, то же поведение, что
        // и до этой фичи для обычных проданных товаров.
        $this->makeMaterialItem($product, 20);

        $this->completeOrder();

        $this->assertNotSame('completed', $this->workOrder->fresh()->status);
        $this->assertTrue(StockMovement::where('product_id', $product->id)->doesntExist());
        $balance = StockBalance::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame('5.000', (string) $balance->quantity);
    }

    #[Test]
    public function stock_deduction_disabled_creates_no_movement_regardless_of_balance(): void
    {
        $product = Product::create(['name' => 'Скотч', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);
        StockBalance::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'quantity' => 100, 'avg_cost' => 500]);

        $this->makeMaterialItem($product, 3, ['stock_deduction_disabled' => true]);

        $this->completeOrder();

        $this->assertSame('completed', $this->workOrder->fresh()->status);
        $this->assertTrue(StockMovement::where('product_id', $product->id)->doesntExist());
        $balance = StockBalance::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame('100.000', (string) $balance->quantity);
    }
}
