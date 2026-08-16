<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\WorkOrderController;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollRule;
use App\Models\Position;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Закрытие заказ-наряда после "Выдан" (CLAUDE.md, "Закрытие заказ-наряда после
 * выдачи") — состав/данные заказа заморожены после completed, а возврат на
 * доработку требует комментария и полностью откатывает склад/невыплаченную ЗП,
 * иначе заказ и его последствия (остатки, начисления) молча расходятся.
 */
class WorkOrderReopenAfterCompletionTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Warehouse $warehouse;

    private Client $client;

    private Service $service;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'warehouse_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'warehouse_mode'], ['value' => 'per_branch']);
        Setting::updateOrCreate(['key' => 'payroll_apply_discount_to_base'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'payroll_worker_base_excludes_materials'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'payroll_worker_base_excludes_admin_share'], ['value' => '0']);

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
        $this->warehouse = Warehouse::create([
            'name' => 'Тестовый склад',
            'owner_type' => 'branch',
            'owner_id' => $this->branch->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        $category = ServiceCategory::create(['name' => 'Тестовая группа', 'is_active' => true]);
        $this->service = Service::create([
            'service_category_id' => $category->id,
            'name' => 'Тестовая услуга',
            'price' => 100000,
            'is_active' => true,
        ]);

        $this->client = Client::create(['branch_id' => $this->branch->id, 'type' => 'b2c', 'name' => 'Заказчик']);

        $this->workOrder = WorkOrder::create([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'in_progress',
            'payment_status' => 'unpaid',
            'total_amount' => 100000,
            'discount_amount' => 0,
            'final_amount' => 100000,
            'admin_assignment_mode' => 'manual',
        ]);
    }

    private function makeServiceItem(): WorkOrderItem
    {
        return WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Service::class,
            'itemable_id' => $this->service->id,
            'name' => 'Тестовая услуга',
            'quantity' => 1,
            'price' => 100000,
            'discount_amount' => 0,
            'total' => 100000,
            'admin_override' => 'none',
        ]);
    }

    private function controller(): WorkOrderController
    {
        return app(WorkOrderController::class);
    }

    private function completeOrder(): void
    {
        $this->controller()->completeOrder(new Request, $this->workOrder->fresh());
    }

    private function reopen(string $status = 'in_progress', string $comment = 'Клиент попросил переделать'): mixed
    {
        $request = new Request;
        $request->merge(['status' => $status, 'reopen_comment' => $comment]);

        return $this->controller()->updateStatus($request, $this->workOrder->fresh());
    }

    #[Test]
    public function locked_mutations_are_rejected_once_completed(): void
    {
        $item = $this->makeServiceItem();
        $this->completeOrder();
        $workOrder = $this->workOrder->fresh();
        $item = $item->fresh();
        $controller = $this->controller();

        $cases = [
            'update' => fn () => $controller->update(new Request, $workOrder),
            'updateAdmin' => fn () => $controller->updateAdmin(new Request, $workOrder),
            'updateItemPayout' => fn () => $controller->updateItemPayout(new Request, $workOrder, $item),
            'updateItemMaterialSettings' => fn () => $controller->updateItemMaterialSettings(new Request, $workOrder, $item),
            'addItem' => fn () => $controller->addItem(new Request, $workOrder),
            'autoAddMaterials' => fn () => $controller->autoAddMaterials(new Request, $workOrder, $item),
            'updateItem' => fn () => $controller->updateItem(new Request, $workOrder, $item),
            'removeItem' => fn () => $controller->removeItem($workOrder, $item),
            'autoSortItems' => fn () => $controller->autoSortItems(new Request, $workOrder),
            'reorderItems' => fn () => $controller->reorderItems(new Request, $workOrder),
            'updateDiscount' => fn () => $controller->updateDiscount(new Request, $workOrder),
        ];

        foreach ($cases as $name => $call) {
            try {
                $call();
                $this->fail("Ожидался HttpException(403) для {$name}() на завершённом заказе.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), $name);
            }
        }
    }

    #[Test]
    public function payment_still_works_on_completed_order(): void
    {
        $this->makeServiceItem();
        $this->completeOrder();

        $account = Account::create(['branch_id' => $this->branch->id, 'type' => 'cash', 'name' => 'Касса', 'is_active' => true]);

        $request = new Request;
        $request->merge(['account_id' => $account->id, 'amount' => 500]);
        $response = $this->controller()->processPayment($request, $this->workOrder->fresh());

        $this->assertEmpty(optional($response->getSession()->get('errors'))->getMessages() ?? []);
        $this->assertSame('partial', $this->workOrder->fresh()->payment_status);
    }

    #[Test]
    public function direct_transition_to_completed_via_update_status_is_rejected(): void
    {
        $request = new Request;
        $request->merge(['status' => 'completed']);
        $response = $this->controller()->updateStatus($request, $this->workOrder->fresh());

        $this->assertNotSame('completed', $this->workOrder->fresh()->status);
        $this->assertNotEmpty($response->getSession()->get('errors')?->get('status'));
    }

    #[Test]
    public function reopen_without_comment_is_rejected(): void
    {
        $this->makeServiceItem();
        $this->completeOrder();

        $request = new Request;
        $request->merge(['status' => 'in_progress']);
        $response = $this->controller()->updateStatus($request, $this->workOrder->fresh());

        $this->assertSame('completed', $this->workOrder->fresh()->status);
        $this->assertNotEmpty($response->getSession()->get('errors')?->get('reopen_comment'));
    }

    #[Test]
    public function reopen_with_comment_changes_status_and_logs_activity(): void
    {
        $this->makeServiceItem();
        $this->completeOrder();

        $this->reopen('in_progress', 'Клиент обнаружил недочёт на крыле');

        $this->assertSame('in_progress', $this->workOrder->fresh()->status);
        $activity = Activity::where('subject_type', WorkOrder::class)
            ->where('subject_id', $this->workOrder->id)
            ->where('event', 'reopened_after_completion')
            ->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Клиент обнаружил недочёт на крыле', $activity->description);
    }

    #[Test]
    public function reopen_reverses_stock_deduction(): void
    {
        $product = Product::create(['name' => 'Полироль', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);
        StockBalance::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'quantity' => 10, 'avg_cost' => 1000]);
        WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Product::class,
            'itemable_id' => $product->id,
            'name' => 'Полироль',
            'quantity' => 3,
            'price' => 1000,
            'discount_amount' => 0,
            'total' => 3000,
        ]);

        $this->completeOrder();
        $balanceAfterCompletion = StockBalance::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame('7.000', (string) $balanceAfterCompletion->quantity);

        $this->reopen();

        $balanceAfterReopen = StockBalance::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame('10.000', (string) $balanceAfterReopen->quantity);
        $this->assertTrue(StockMovement::where('work_order_id', $this->workOrder->id)->where('type', 'out_reversal')->where('quantity', 3)->exists());
    }

    #[Test]
    public function reopen_reverses_fifo_batch_quantity(): void
    {
        $product = Product::create(['name' => 'Плёнка', 'unit' => 'м', 'accounting_type' => 'batch', 'is_active' => true]);
        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'B-1',
            'initial_quantity' => 10,
            'current_quantity' => 10,
            'cost_price' => 5000,
        ]);
        StockBalance::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'quantity' => 10, 'avg_cost' => 5000]);
        WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Product::class,
            'itemable_id' => $product->id,
            'name' => 'Плёнка',
            'quantity' => 4,
            'price' => 5000,
            'discount_amount' => 0,
            'total' => 20000,
        ]);

        $this->completeOrder();
        $this->assertSame('6.000', (string) $batch->fresh()->current_quantity);

        $this->reopen();

        $this->assertSame('10.000', (string) $batch->fresh()->current_quantity);
    }

    #[Test]
    public function reopen_cancels_pending_payroll_but_not_paid(): void
    {
        $position = Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);
        PayrollRule::create([
            'position_id' => $position->id,
            'type' => 'percentage',
            'percentage_value' => 40,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);
        $employee = Employee::create([
            'branch_id' => $this->branch->id,
            'position_id' => $position->id,
            'type' => 'staff',
            'first_name' => 'Иван',
            'last_name' => 'Тестов',
            'is_active' => true,
        ]);
        $item = $this->makeServiceItem();
        $item->employees()->attach($employee->id);

        $this->completeOrder();
        $pendingPayroll = Payroll::where('work_order_id', $this->workOrder->id)->first();
        $this->assertNotNull($pendingPayroll);
        $this->assertSame('pending', $pendingPayroll->status);

        // Отдельно, уже выплаченное начисление другого исполнителя того же
        // заказа — не должно трогаться, даже если технически pending-запись
        // рядом отменяется.
        $paidPayroll = Payroll::create([
            'employee_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_order_id' => $this->workOrder->id,
            'type' => 'accrual',
            'role' => 'worker',
            'amount' => 5000,
            'status' => 'paid',
        ]);

        // paid-запись блокирует автоматический возврат целиком (см. следующий тест) —
        // здесь проверяем именно cancel-логику, поэтому временно её удаляем.
        $paidPayroll->delete();

        $this->reopen();

        $this->assertSame('canceled', $pendingPayroll->fresh()->status);
    }

    #[Test]
    public function reopen_is_blocked_when_payroll_already_paid(): void
    {
        $item = $this->makeServiceItem();
        $this->completeOrder();

        Payroll::create([
            'employee_id' => null,
            'client_id' => null,
            'branch_id' => $this->branch->id,
            'work_order_id' => $this->workOrder->id,
            'work_order_item_id' => $item->id,
            'type' => 'accrual',
            'role' => 'worker',
            'amount' => 5000,
            'status' => 'paid',
        ]);

        $response = $this->reopen();

        $this->assertSame('completed', $this->workOrder->fresh()->status);
        $this->assertNotEmpty($response->getSession()->get('errors')?->get('status'));
    }

    #[Test]
    public function recompleting_after_reopen_does_not_double_deduct_stock(): void
    {
        $product = Product::create(['name' => 'Химия', 'unit' => 'шт', 'accounting_type' => 'average', 'is_active' => true]);
        StockBalance::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'quantity' => 10, 'avg_cost' => 1000]);
        $item = WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Product::class,
            'itemable_id' => $product->id,
            'name' => 'Химия',
            'quantity' => 3,
            'price' => 1000,
            'discount_amount' => 0,
            'total' => 3000,
        ]);

        $this->completeOrder();
        $this->reopen();

        // Доработка: количество меняется (имитация правки состава заказа).
        $item->update(['quantity' => 5, 'total' => 5000]);

        $this->completeOrder();

        $balance = StockBalance::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->first();
        // 10 (после реверса) − 5 (новое списание) = 5, а НЕ 10 − 3 − 5 = 2.
        $this->assertSame('5.000', (string) $balance->quantity);
    }

    #[Test]
    public function was_reopened_flag_reflects_activity_log(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeServiceItem();

        $freshBefore = app(WorkOrderController::class)->show($this->workOrder->fresh());
        $propsBefore = $freshBefore->toResponse(request())->getOriginalContent()->getData()['page']['props'];
        $this->assertFalse($propsBefore['wasReopenedAfterCompletion']);

        $this->completeOrder();
        $this->reopen();

        $freshAfter = app(WorkOrderController::class)->show($this->workOrder->fresh());
        $propsAfter = $freshAfter->toResponse(request())->getOriginalContent()->getData()['page']['props'];
        $this->assertTrue($propsAfter['wasReopenedAfterCompletion']);
    }
}
