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
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Product.allow_negative_stock_by_default (CLAUDE.md «Отдельные тумблеры
 * разрешения списания в минус для материалов и для товаров на продажу») —
 * дефолт с карточки товара действует одинаково для материала (привязан к
 * услуге) и для обычной проданной позиции, а per-line можно переопределить
 * через updateItemMaterialSettings(). Материал-специфичные поля (is_billable
 * и т.п.) для обычного товара сервер молча игнорирует, а не отклоняет всю
 * позицию, — иначе первый же лишний параметр во фронтовом запросе ломал бы
 * сохранение единственного релевантного тумблера.
 */
class WorkOrderNegativeStockDefaultsTest extends TenantAgentTestCase
{
    private Branch $branch;

    private WorkOrder $workOrder;

    private WorkOrderItem $serviceItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
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
            'discount_is_manual' => true,
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

    private function addItem(array $overrides = []): void
    {
        $request = new Request;
        $request->merge(array_merge([
            'itemable_type' => 'product',
            'quantity' => 1,
            'price' => 10,
        ], $overrides));

        app(WorkOrderController::class)->addItem($request, $this->workOrder->fresh());
    }

    #[Test]
    public function standalone_product_with_default_true_gets_allow_negative_stock_on_creation(): void
    {
        $product = Product::create(['name' => 'Плёнка', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true, 'allow_negative_stock_by_default' => true]);

        $this->addItem(['itemable_id' => $product->id, 'name' => 'Плёнка']);

        $item = WorkOrderItem::where('work_order_id', $this->workOrder->id)->where('itemable_id', $product->id)->first();
        $this->assertTrue($item->allow_negative_stock);
        $this->assertNull($item->linked_item_id);
    }

    #[Test]
    public function standalone_product_with_default_false_does_not_get_allow_negative_stock(): void
    {
        $product = Product::create(['name' => 'Химия', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);

        $this->addItem(['itemable_id' => $product->id, 'name' => 'Химия']);

        $item = WorkOrderItem::where('work_order_id', $this->workOrder->id)->where('itemable_id', $product->id)->first();
        $this->assertFalse($item->allow_negative_stock);
    }

    #[Test]
    public function material_also_inherits_the_product_catalog_default(): void
    {
        $product = Product::create(['name' => 'Полироль', 'unit' => 'мл', 'accounting_type' => 'average', 'is_active' => true, 'allow_negative_stock_by_default' => true]);

        $this->addItem(['itemable_id' => $product->id, 'name' => 'Полироль', 'linked_item_id' => $this->serviceItem->id]);

        $item = WorkOrderItem::where('work_order_id', $this->workOrder->id)->where('linked_item_id', $this->serviceItem->id)->first();
        $this->assertNotNull($item);
        $this->assertTrue($item->allow_negative_stock);
        $this->assertFalse($item->is_billable);
    }

    #[Test]
    public function auto_add_bypasses_insufficiency_skip_when_product_default_allows_negative(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        $warehouse = Warehouse::create(['name' => 'Тестовый склад', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_default' => true, 'is_active' => true]);
        $product = Product::create(['name' => 'Полироль', 'unit' => 'мл', 'accounting_type' => 'average', 'is_active' => true, 'allow_negative_stock_by_default' => true]);
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 1, 'avg_cost' => 500]);

        $request = new Request;
        $request->merge(['materials' => [
            ['product_id' => $product->id, 'quantity' => 50, 'force_negative' => false],
        ]]);
        app(WorkOrderController::class)->autoAddMaterials($request, $this->workOrder->fresh(), $this->serviceItem->fresh());

        $material = WorkOrderItem::where('linked_item_id', $this->serviceItem->id)->first();
        $this->assertNotNull($material, 'Материал должен быть добавлен — дефолт товара разрешает минус.');
        $this->assertSame('50.000', (string) $material->quantity);
    }

    #[Test]
    public function auto_add_still_skips_insufficiency_when_product_default_is_false(): void
    {
        Setting::updateOrCreate(['key' => 'warehouse_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        $warehouse = Warehouse::create(['name' => 'Тестовый склад', 'owner_type' => 'branch', 'owner_id' => $this->branch->id, 'is_default' => true, 'is_active' => true]);
        $product = Product::create(['name' => 'Полироль', 'unit' => 'мл', 'accounting_type' => 'average', 'is_active' => true]);
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 1, 'avg_cost' => 500]);

        $request = new Request;
        $request->merge(['materials' => [
            ['product_id' => $product->id, 'quantity' => 50, 'force_negative' => false],
        ]]);
        app(WorkOrderController::class)->autoAddMaterials($request, $this->workOrder->fresh(), $this->serviceItem->fresh());

        $this->assertTrue(WorkOrderItem::where('linked_item_id', $this->serviceItem->id)->doesntExist());
    }

    #[Test]
    public function settings_endpoint_accepts_negative_stock_but_ignores_material_only_fields_for_plain_product(): void
    {
        $product = Product::create(['name' => 'Химия', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);
        $item = WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Product::class,
            'itemable_id' => $product->id,
            'name' => 'Химия',
            'quantity' => 1,
            'price' => 10000,
            'discount_amount' => 0,
            'total' => 10000,
        ]);

        $request = new Request;
        $request->merge(['allow_negative_stock' => true, 'is_billable' => false]);
        app(WorkOrderController::class)->updateItemMaterialSettings($request, $this->workOrder->fresh(), $item->fresh());

        $item->refresh();
        $this->assertTrue($item->allow_negative_stock);
        // is_billable у обычного товара не материал-специфичное поле, но сервер
        // его не принимает через этот эндпоинт для не-материала — билл клиента
        // не должен молча измениться от лишнего параметра в запросе.
        $this->assertTrue($item->is_billable);
    }

    #[Test]
    public function settings_endpoint_rejects_service_type_items(): void
    {
        $this->expectException(HttpException::class);

        $request = new Request;
        $request->merge(['allow_negative_stock' => true]);
        app(WorkOrderController::class)->updateItemMaterialSettings($request, $this->workOrder->fresh(), $this->serviceItem->fresh());
    }
}
