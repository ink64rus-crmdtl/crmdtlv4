<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\WorkOrderController;
use App\Models\Branch;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\LegalEntity;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\Documents\DocumentPlaceholderService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * НДС на заказ-наряде — WorkOrderController::recalculateTotals(). Ключевая
 * гарантия, которую здесь проверяем: когда у юрлица заказа НДС выключен
 * (или юрлицо не резолвится), total_amount/discount_amount/final_amount
 * считаются ПОБАЙТОВО так же, как до появления НДС в системе — ни один
 * существующий тенант без НДС не должен увидеть изменений в суммах после
 * этой доработки. recalculateTotals() приватный (та же логика, что и
 * пересчёт скидки, наружу не выносился) — вызываем через рефлексию, как
 * единственный способ проверить формулу без полного HTTP-стека.
 */
class WorkOrderVatCalculationTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Client $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация НДС']);
        $category = ServiceCategory::create(['name' => 'Тестовая группа НДС', 'is_active' => true]);
        $this->service = Service::create([
            'service_category_id' => $category->id,
            'name' => 'Тестовая услуга НДС',
            'price' => 100000,
            'is_active' => true,
        ]);
        $this->client = Client::create([
            'branch_id' => $this->branch->id,
            'type' => 'b2c',
            'name' => 'Заказчик НДС',
        ]);
    }

    private function makeWorkOrder(?LegalEntity $legalEntity = null): WorkOrder
    {
        $workOrder = WorkOrder::create([
            'branch_id' => $this->branch->id,
            'legal_entity_id' => $legalEntity?->id,
            'client_id' => $this->client->id,
            'status' => 'in_progress',
            'payment_status' => 'unpaid',
            'total_amount' => 0,
            'discount_amount' => 0,
            'final_amount' => 0,
            'discount_is_manual' => true, // без скидки клиента — не мешает формуле НДС
            'admin_assignment_mode' => 'manual',
        ]);

        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'itemable_type' => Service::class,
            'itemable_id' => $this->service->id,
            'name' => 'Тестовая услуга НДС',
            'quantity' => 1,
            'price' => 100000, // 1000 ₽
            'discount_amount' => 0,
            'total' => 100000,
            'admin_override' => 'none',
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

    private function makeLegalEntity(bool $vatPayer, ?int $rate = null, ?string $method = null): LegalEntity
    {
        $legalEntity = LegalEntity::create([
            'name' => 'ООО Тест НДС '.uniqid(),
            'is_active' => true,
            'vat_payer' => $vatPayer,
            'vat_rate' => $rate,
            'vat_calculation_method' => $method,
        ]);
        $legalEntity->branches()->attach($this->branch->id);

        return $legalEntity;
    }

    #[Test]
    public function without_legal_entity_totals_match_pre_vat_formula(): void
    {
        $workOrder = $this->recalculate($this->makeWorkOrder());

        $this->assertSame(100000, $workOrder->total_amount);
        $this->assertSame(0, $workOrder->discount_amount);
        $this->assertSame(100000, $workOrder->final_amount);
        $this->assertNull($workOrder->vat_rate);
        $this->assertSame(0, $workOrder->vat_amount);
    }

    #[Test]
    public function non_vat_payer_legal_entity_totals_match_pre_vat_formula(): void
    {
        $legalEntity = $this->makeLegalEntity(vatPayer: false);
        $workOrder = $this->recalculate($this->makeWorkOrder($legalEntity));

        $this->assertSame(100000, $workOrder->total_amount);
        $this->assertSame(100000, $workOrder->final_amount);
        $this->assertNull($workOrder->vat_rate);
        $this->assertSame(0, $workOrder->vat_amount);
    }

    #[Test]
    public function inclusive_vat_extracts_amount_without_changing_final_amount(): void
    {
        $legalEntity = $this->makeLegalEntity(vatPayer: true, rate: 20, method: 'inclusive');
        $workOrder = $this->recalculate($this->makeWorkOrder($legalEntity));

        // 1000 ₽ уже включают 20% НДС — выделяем: 1000 * 20 / 120 = 166.67 ₽ ≈ 16667 коп.
        $this->assertSame(100000, $workOrder->total_amount);
        $this->assertSame(100000, $workOrder->final_amount, 'final_amount не должен меняться в inclusive-режиме');
        $this->assertSame(20, $workOrder->vat_rate);
        $this->assertSame('inclusive', $workOrder->vat_calculation_method);
        $this->assertSame(16667, $workOrder->vat_amount);
    }

    #[Test]
    public function exclusive_vat_adds_amount_on_top_of_final_amount(): void
    {
        $legalEntity = $this->makeLegalEntity(vatPayer: true, rate: 20, method: 'exclusive');
        $workOrder = $this->recalculate($this->makeWorkOrder($legalEntity));

        // 1000 ₽ без НДС + 20% сверху = 200 ₽ налога, итог 1200 ₽.
        $this->assertSame(100000, $workOrder->total_amount);
        $this->assertSame(20000, $workOrder->vat_amount);
        $this->assertSame(120000, $workOrder->final_amount, 'final_amount обязан вырасти на сумму НДС в exclusive-режиме');
        $this->assertSame(20, $workOrder->vat_rate);
    }

    #[Test]
    public function vat_rate_and_method_are_snapshotted_on_the_order(): void
    {
        $legalEntity = $this->makeLegalEntity(vatPayer: true, rate: 10, method: 'exclusive');
        $workOrder = $this->recalculate($this->makeWorkOrder($legalEntity));

        $this->assertSame(10, $workOrder->vat_rate);
        $this->assertSame('exclusive', $workOrder->vat_calculation_method);

        // Юрлицо меняет ставку — уже пересчитанный заказ не обязан следовать
        // за ним автоматически (снепшот, не живая ссылка), пока не будет
        // пересчитан заново явным действием (эта же формула сработает и тогда).
        $legalEntity->update(['vat_rate' => 20]);
        $workOrder->refresh();
        $this->assertSame(10, $workOrder->vat_rate, 'ставка на заказе не должна молча съехать вслед за юрлицом');
    }

    #[Test]
    public function zero_percent_vat_rate_still_marks_order_as_vat_tracked(): void
    {
        $legalEntity = $this->makeLegalEntity(vatPayer: true, rate: 0, method: 'inclusive');
        $workOrder = $this->recalculate($this->makeWorkOrder($legalEntity));

        // Ставка 0% — валидный режим НДС (например экспорт/льгота), а не
        // "НДС выключен" — на UI и в документах это должно отличаться от
        // случая vat_rate === null (см. Show.vue: v-if="vat_rate !== null").
        $this->assertSame(0, $workOrder->vat_rate);
        $this->assertNotNull($workOrder->vat_rate);
        $this->assertSame(0, $workOrder->vat_amount);
        $this->assertSame(100000, $workOrder->final_amount);
    }

    #[Test]
    public function placeholder_vat_flags_reflect_all_three_states(): void
    {
        // Без юрлица/НДС — оба флага-условия ({{#if}} в DocumentRenderer) пусты.
        $flat = DocumentPlaceholderService::buildFor('work_order', $this->recalculate($this->makeWorkOrder()))['flat'];
        $this->assertSame('', $flat['work_order.vat_inclusive']);
        $this->assertSame('', $flat['work_order.vat_exclusive']);

        $inclusiveEntity = $this->makeLegalEntity(vatPayer: true, rate: 20, method: 'inclusive');
        $flat = DocumentPlaceholderService::buildFor('work_order', $this->recalculate($this->makeWorkOrder($inclusiveEntity)))['flat'];
        $this->assertSame('1', $flat['work_order.vat_inclusive']);
        $this->assertSame('', $flat['work_order.vat_exclusive']);

        $exclusiveEntity = $this->makeLegalEntity(vatPayer: true, rate: 20, method: 'exclusive');
        $flat = DocumentPlaceholderService::buildFor('work_order', $this->recalculate($this->makeWorkOrder($exclusiveEntity)))['flat'];
        $this->assertSame('', $flat['work_order.vat_inclusive']);
        $this->assertSame('1', $flat['work_order.vat_exclusive']);
    }

    #[Test]
    public function goods_receipt_placeholder_vat_flags_reflect_header_method_and_per_item_presence(): void
    {
        $supplier = Client::create(['branch_id' => $this->branch->id, 'type' => 'b2c', 'name' => 'Поставщик НДС']);
        $warehouse = Warehouse::create(['name' => 'Склад НДС']);
        $productWithVat = Product::create(['name' => 'Товар с НДС']);
        $productWithoutVat = Product::create(['name' => 'Товар без НДС']);

        $receipt = GoodsReceipt::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'branch_id' => $this->branch->id,
            'receipt_date' => now()->toDateString(),
            'status' => 'posted',
            'vat_calculation_method' => 'exclusive',
        ]);

        GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $productWithVat->id,
            'quantity' => 1,
            'cost_price' => 100000,
            'vat_rate' => 20,
            'vat_amount' => 20000,
        ]);
        GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $productWithoutVat->id,
            'quantity' => 1,
            'cost_price' => 50000,
            'vat_rate' => null,
            'vat_amount' => 0,
        ]);

        $data = DocumentPlaceholderService::buildFor('goods_receipt', $receipt->fresh(['items']));

        $this->assertSame('', $data['flat']['goods_receipt.vat_inclusive']);
        $this->assertSame('1', $data['flat']['goods_receipt.vat_exclusive']);

        $rows = $data['tables']['items'];
        $this->assertSame('1', $rows[0]['item.has_vat'], 'у позиции с vat_rate флаг обязан быть истинным');
        $this->assertSame('', $rows[1]['item.has_vat'], 'у позиции без НДС флаг обязан быть пустым');
    }
}
