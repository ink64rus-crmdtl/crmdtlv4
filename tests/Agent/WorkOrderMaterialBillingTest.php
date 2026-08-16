<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\WorkOrderController;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\Documents\DocumentPlaceholderService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Материал на услугу (CLAUDE.md «Материалы на услугу») — is_billable
 * определяет, входит ли позиция в сумму к оплате клиентом
 * (recalculateTotals()) и в печатную табличную секцию документа
 * (DocumentPlaceholderService). Это единственная гарантия, ради которой
 * весь Вариант C затевался: без нея добавление материала реально увеличило
 * бы счёт клиента (живой баг, найденный при анализе перед реализацией).
 */
class WorkOrderMaterialBillingTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Client $client;

    private Service $service;

    private Product $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
        $category = ServiceCategory::create(['name' => 'Тестовая группа', 'is_active' => true]);
        $this->service = Service::create([
            'service_category_id' => $category->id,
            'name' => 'Тестовая услуга',
            'price' => 100000,
            'is_active' => true,
        ]);
        $this->client = Client::create(['branch_id' => $this->branch->id, 'type' => 'b2c', 'name' => 'Заказчик']);
        $this->material = Product::create(['name' => 'Тестовый материал', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);
    }

    private function makeWorkOrderWithMaterial(bool $isBillable): WorkOrder
    {
        $workOrder = WorkOrder::create([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'in_progress',
            'payment_status' => 'unpaid',
            'total_amount' => 0,
            'discount_amount' => 0,
            'final_amount' => 0,
            'discount_is_manual' => true,
            'admin_assignment_mode' => 'manual',
        ]);

        $serviceItem = WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'itemable_type' => Service::class,
            'itemable_id' => $this->service->id,
            'name' => 'Тестовая услуга',
            'quantity' => 1,
            'price' => 100000, // 1000 ₽
            'discount_amount' => 0,
            'total' => 100000,
        ]);

        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'itemable_type' => Product::class,
            'itemable_id' => $this->material->id,
            'linked_item_id' => $serviceItem->id,
            'name' => 'Тестовый материал',
            'quantity' => 1,
            'price' => 30000, // 300 ₽
            'discount_amount' => 0,
            'total' => 30000,
            'is_billable' => $isBillable,
            'affects_payroll' => true,
        ]);

        return $workOrder->fresh();
    }

    private function recalculate(WorkOrder $workOrder): WorkOrder
    {
        $method = new ReflectionMethod(WorkOrderController::class, 'recalculateTotals');
        $method->setAccessible(true);
        $method->invoke(new WorkOrderController, $workOrder);

        return $workOrder->fresh();
    }

    #[Test]
    public function non_billable_material_does_not_affect_total_amount(): void
    {
        $workOrder = $this->recalculate($this->makeWorkOrderWithMaterial(isBillable: false));

        // 1000 ₽ услуги, 300 ₽ материала СКРЫТЫ — клиент платит только за услугу.
        $this->assertSame(100000, $workOrder->total_amount);
        $this->assertSame(100000, $workOrder->final_amount);
    }

    #[Test]
    public function non_billable_material_is_absent_from_generated_document_rows(): void
    {
        $workOrder = $this->recalculate($this->makeWorkOrderWithMaterial(isBillable: false));

        $data = DocumentPlaceholderService::buildFor('work_order', $workOrder->fresh(['items']));

        $this->assertCount(1, $data['tables']['items']);
        $this->assertSame('Тестовая услуга', $data['tables']['items'][0]['item.name']);
        $this->assertSame('1 000,00', $data['flat']['items_total']);
    }

    #[Test]
    public function billable_material_is_included_in_total_and_in_document(): void
    {
        // Регрессионный якорь: владелец сознательно решает продать материал
        // клиенту (is_billable=true) — флаг должен пропускать его И в счёт,
        // И в документ, а не прятать всегда безусловно.
        $workOrder = $this->recalculate($this->makeWorkOrderWithMaterial(isBillable: true));

        $this->assertSame(130000, $workOrder->total_amount);
        $this->assertSame(130000, $workOrder->final_amount);

        $data = DocumentPlaceholderService::buildFor('work_order', $workOrder->fresh(['items']));
        $this->assertCount(2, $data['tables']['items']);
        $this->assertSame('1 300,00', $data['flat']['items_total']);
    }

    #[Test]
    public function nested_auto_sort_places_materials_right_after_their_service(): void
    {
        $workOrder = $this->makeWorkOrderWithMaterial(isBillable: false);
        $items = $workOrder->items()->get();
        $serviceItem = $items->firstWhere('itemable_type', Service::class);
        $materialItem = $items->firstWhere('linked_item_id', $serviceItem->id);

        $secondService = WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'itemable_type' => Service::class,
            'itemable_id' => $this->service->id,
            'name' => 'Вторая услуга',
            'quantity' => 1,
            'price' => 50000,
            'discount_amount' => 0,
            'total' => 50000,
        ]);

        // Смоделируем порядок "сначала все услуги, потом материал" — так, как раскладывает режим 'grouped'.
        WorkOrderItem::where('id', $serviceItem->id)->update(['sort_order' => 0]);
        WorkOrderItem::where('id', $secondService->id)->update(['sort_order' => 1]);
        WorkOrderItem::where('id', $materialItem->id)->update(['sort_order' => 2]);

        $request = new Request;
        $request->merge(['mode' => 'nested']);
        app(WorkOrderController::class)->autoSortItems($request, $workOrder->fresh());

        $orderedIds = $workOrder->items()->get()->pluck('id')->all();
        $this->assertSame([$serviceItem->id, $materialItem->id, $secondService->id], $orderedIds);
    }

    #[Test]
    public function grouped_auto_sort_keeps_services_together_ahead_of_materials(): void
    {
        // Регрессионный якорь: режим по умолчанию не меняется этой доработкой.
        $workOrder = $this->makeWorkOrderWithMaterial(isBillable: false);
        $items = $workOrder->items()->get();
        $serviceItem = $items->firstWhere('itemable_type', Service::class);
        $materialItem = $items->firstWhere('linked_item_id', $serviceItem->id);

        // Материал стоит перед услугой — auto-sort(grouped) обязан вернуть услугу наверх.
        WorkOrderItem::where('id', $materialItem->id)->update(['sort_order' => 0]);
        WorkOrderItem::where('id', $serviceItem->id)->update(['sort_order' => 1]);

        $request = new Request;
        $request->merge(['mode' => 'grouped']);
        app(WorkOrderController::class)->autoSortItems($request, $workOrder->fresh());

        $orderedIds = $workOrder->items()->get()->pluck('id')->all();
        $this->assertSame([$serviceItem->id, $materialItem->id], $orderedIds);
    }
}
