<?php

namespace Tests\Agent;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\PayrollRule;
use App\Models\Position;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\PayrollCalculationService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Расчёт ЗП — единственное место в системе, где из данных заказа получаются
 * реальные деньги людям, поэтому он покрыт тестами плотнее остального кода.
 * Тесты идут против физической sandbox-БД тенанта (см. TenantAgentTestCase),
 * каждый — в транзакции с откатом.
 *
 * Здесь сознательно проверяется не только новая функциональность (подрядчики),
 * но и существовавшее до неё поведение штатной бригады: смысл этих тестов —
 * поймать регрессию в уже работающих расчётах, а не только зафиксировать
 * новые.
 */
class PayrollCalculationTest extends TenantAgentTestCase
{
    private Branch $branch;

    private ServiceCategory $category;

    private Service $service;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setPayrollSettings();

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
        $this->category = ServiceCategory::create(['name' => 'Тестовая группа', 'is_active' => true]);
        $this->service = Service::create([
            'service_category_id' => $this->category->id,
            'name' => 'Тестовая услуга',
            'price' => 100000, // 1000 ₽
            'is_active' => true,
        ]);

        $client = Client::create([
            'branch_id' => $this->branch->id,
            'type' => 'b2c',
            'name' => 'Заказчик',
        ]);

        $this->workOrder = WorkOrder::create([
            'branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'status' => 'in_progress',
            'payment_status' => 'unpaid',
            'total_amount' => 0,
            'discount_amount' => 0,
            'final_amount' => 0,
            'admin_assignment_mode' => 'manual',
        ]);
    }

    /**
     * Настройки расчёта фиксируем явно: в sandbox-БД могли остаться значения
     * от других прогонов, а от них напрямую зависят суммы в этих тестах.
     */
    private function setPayrollSettings(array $overrides = []): void
    {
        $settings = array_merge([
            'payroll_apply_discount_to_base' => '1',
            'payroll_worker_base_excludes_materials' => '1',
            'payroll_worker_base_excludes_admin_share' => '0',
            'payroll_default_self_employed_tax_percent' => '6',
        ], $overrides);

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function makeItem(int $price = 100000, float $quantity = 1): WorkOrderItem
    {
        return WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'itemable_type' => Service::class,
            'itemable_id' => $this->service->id,
            'name' => 'Тестовая услуга',
            'quantity' => $quantity,
            'price' => $price,
            'discount_amount' => 0,
            'total' => (int) round($price * $quantity),
            'admin_override' => 'none',
        ]);
    }

    private function makeEmployee(string $type = 'staff', ?Position $position = null): Employee
    {
        $position ??= Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);

        return Employee::create([
            'branch_id' => $this->branch->id,
            'position_id' => $position->id,
            'type' => $type,
            'first_name' => 'Иван',
            'last_name' => 'Тестов'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeContractor(string $name = 'ООО Подрядчик'): Client
    {
        return Client::create([
            'branch_id' => $this->branch->id,
            'type' => 'b2b',
            'name' => $name,
        ]);
    }

    // --- Штатная бригада: поведение, существовавшее до подрядчиков ---

    #[Test]
    public function two_workers_without_explicit_shares_split_the_base_equally(): void
    {
        $position = Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);
        PayrollRule::create([
            'position_id' => $position->id,
            'type' => 'percentage',
            'percentage_value' => 40,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000);
        $item->employees()->attach([
            $this->makeEmployee('staff', $position)->id,
            $this->makeEmployee('staff', $position)->id,
        ]);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        // 1000 ₽ * 40% = 400 ₽ на бригаду, поровну на двоих = по 200 ₽.
        $this->assertCount(2, $result['rows']);
        $this->assertSame([20000, 20000], array_column($result['rows'], 'amount'));
        $this->assertEmpty($result['skipped']);
    }

    #[Test]
    public function explicit_shares_override_the_equal_split(): void
    {
        $position = Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);
        PayrollRule::create([
            'position_id' => $position->id,
            'type' => 'percentage',
            'percentage_value' => 50,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000);
        $first = $this->makeEmployee('staff', $position);
        $second = $this->makeEmployee('staff', $position);
        $item->employees()->attach($first->id, ['share_percent' => 70]);
        $item->employees()->attach($second->id, ['share_percent' => 30]);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $byEmployee = collect($result['rows'])->keyBy('employee_id');
        // 1000 ₽ * 70% доли * 50% ставки = 350 ₽ / 150 ₽.
        $this->assertSame(35000, $byEmployee[$first->id]['amount']);
        $this->assertSame(15000, $byEmployee[$second->id]['amount']);
    }

    #[Test]
    public function manual_amount_override_wins_over_the_configured_rate(): void
    {
        $position = Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);
        PayrollRule::create([
            'position_id' => $position->id,
            'type' => 'percentage',
            'percentage_value' => 40,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000);
        $employee = $this->makeEmployee('staff', $position);
        $item->employees()->attach($employee->id, ['manual_amount_override' => 12345]);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $this->assertSame(12345, $result['rows'][0]['amount']);
    }

    #[Test]
    public function worker_without_any_rate_is_skipped_with_a_note_instead_of_silently_getting_zero(): void
    {
        $item = $this->makeItem(100000);
        $item->employees()->attach($this->makeEmployee()->id);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $this->assertEmpty($result['rows']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('не настроена ставка исполнителя', $result['skipped'][0]);
    }

    // --- Подрядчики (Client с ролью «Подрядчик») ---

    #[Test]
    public function contractor_is_paid_the_manually_entered_amount(): void
    {
        $item = $this->makeItem(100000);
        $contractor = $this->makeContractor();
        $item->contractors()->attach($contractor->id, ['manual_amount_override' => 60000]);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $this->assertCount(1, $result['rows']);
        $this->assertSame(60000, $result['rows'][0]['amount']);
        // Начисление адресовано клиенту, а не сотруднику.
        $this->assertNull($result['rows'][0]['employee_id']);
        $this->assertSame($contractor->id, $result['rows'][0]['client_id']);
        $this->assertSame('worker', $result['rows'][0]['role']);
    }

    #[Test]
    public function contractor_fixed_rate_is_multiplied_by_quantity(): void
    {
        $contractor = $this->makeContractor();
        PayrollRule::create([
            'client_id' => $contractor->id,
            'type' => 'fixed',
            'fixed_amount' => 25000, // 250 ₽ за единицу
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000, 3);
        $item->contractors()->attach($contractor->id);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $this->assertSame(75000, $result['rows'][0]['amount']);
    }

    #[Test]
    public function contractor_percentage_rate_applies_to_the_full_worker_base(): void
    {
        $contractor = $this->makeContractor();
        PayrollRule::create([
            'client_id' => $contractor->id,
            'service_id' => $this->service->id,
            'type' => 'percentage',
            'percentage_value' => 60,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000);
        $item->contractors()->attach($contractor->id);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        // Подрядчик вне пула долей — получает 60% от всей базы, а не от доли.
        $this->assertSame(60000, $result['rows'][0]['amount']);
    }

    #[Test]
    public function contractor_without_rate_or_manual_amount_is_skipped_with_a_note(): void
    {
        $item = $this->makeItem(100000);
        $item->contractors()->attach($this->makeContractor('ООО Безставки')->id);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $this->assertEmpty($result['rows']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('ООО Безставки', $result['skipped'][0]);
        $this->assertStringContainsString('не настроена ставка подрядчика', $result['skipped'][0]);
    }

    #[Test]
    public function two_contractors_each_get_their_own_amount_without_splitting_the_base(): void
    {
        $item = $this->makeItem(100000);
        $item->contractors()->attach($this->makeContractor('Первый')->id, ['manual_amount_override' => 40000]);
        $item->contractors()->attach($this->makeContractor('Второй')->id, ['manual_amount_override' => 40000]);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        // Ни один не «урезан» вдвое из-за наличия второго — доли к подрядчикам
        // не применяются.
        $this->assertSame([40000, 40000], array_column($result['rows'], 'amount'));
    }

    // --- Изоляция ставок и совместимость ролей ---

    #[Test]
    public function contractor_rate_is_never_matched_for_a_staff_employee(): void
    {
        // Ставка заведена на подрядчика; штатный сотрудник с той же услугой
        // не должен её «унаследовать» — иначе человек получил бы чужие деньги.
        $contractor = $this->makeContractor();
        PayrollRule::create([
            'client_id' => $contractor->id,
            'type' => 'percentage',
            'percentage_value' => 90,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000);
        $item->employees()->attach($this->makeEmployee()->id);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $this->assertEmpty($result['rows']);
        $this->assertStringContainsString('не настроена ставка исполнителя', $result['skipped'][0]);
    }

    #[Test]
    public function employee_rate_is_never_matched_for_a_contractor(): void
    {
        $position = Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);
        PayrollRule::create([
            'position_id' => $position->id,
            'type' => 'percentage',
            'percentage_value' => 90,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000);
        $item->contractors()->attach($this->makeContractor()->id);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $this->assertEmpty($result['rows']);
        $this->assertStringContainsString('не настроена ставка подрядчика', $result['skipped'][0]);
    }

    #[Test]
    public function item_can_have_both_an_admin_and_a_contractor(): void
    {
        $adminPosition = Position::create(['name' => 'Администратор', 'is_active' => true, 'payroll_role' => 'admin']);
        PayrollRule::create([
            'position_id' => $adminPosition->id,
            'type' => 'percentage',
            'percentage_value' => 10,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $admin = $this->makeEmployee('staff', $adminPosition);
        $this->workOrder->admins()->attach($admin->id);

        $item = $this->makeItem(100000);
        $item->update(['admin_override' => 'inherit']);
        $contractor = $this->makeContractor();
        $item->contractors()->attach($contractor->id, ['manual_amount_override' => 50000]);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        $byRole = collect($result['rows'])->keyBy('role');
        $this->assertSame(10000, $byRole['admin']['amount']);
        $this->assertSame($admin->id, $byRole['admin']['employee_id']);
        $this->assertSame(50000, $byRole['worker']['amount']);
        $this->assertSame($contractor->id, $byRole['worker']['client_id']);
    }

    #[Test]
    public function attaching_a_contractor_stores_the_polymorphic_type_and_does_not_leak_into_employees(): void
    {
        $item = $this->makeItem();
        $employee = $this->makeEmployee();
        $contractor = $this->makeContractor();

        $item->employees()->attach($employee->id);
        $item->contractors()->attach($contractor->id);

        $item->refresh();

        // Обе связи ходят в одну таблицу, поэтому важно, что они не видят
        // записи друг друга — иначе Client отдавался бы как Employee.
        $this->assertSame([$employee->id], $item->employees->pluck('id')->all());
        $this->assertSame([$contractor->id], $item->contractors->pluck('id')->all());
    }

    #[Test]
    public function every_staff_employee_counts_towards_the_equal_split_regardless_of_type(): void
    {
        // Раньше сотрудник с type='outsource' исключался из знаменателя равной
        // доли. Этот тип упразднён (подрядчик теперь Client), и никакой тип
        // сотрудника больше не выпадает из пула — иначе оставшиеся получили бы
        // завышенные доли.
        $position = Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);
        PayrollRule::create([
            'position_id' => $position->id,
            'type' => 'percentage',
            'percentage_value' => 100,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $item = $this->makeItem(100000);
        $item->employees()->attach($this->makeEmployee('staff', $position)->id);
        $item->employees()->attach($this->makeEmployee('self_employed', $position)->id);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());

        // Оба делят базу пополам: 500 ₽ штатному и 500 ₽ + 6% налога самозанятому.
        $amounts = array_column($result['rows'], 'amount');
        sort($amounts);
        $this->assertSame([50000, 53000], $amounts);
    }

    #[Test]
    public function self_employed_gross_up_applies_to_staff_but_not_to_contractors(): void
    {
        $position = Position::create(['name' => 'Мастер', 'is_active' => true, 'payroll_role' => 'worker']);
        PayrollRule::create([
            'position_id' => $position->id,
            'type' => 'percentage',
            'percentage_value' => 50,
            'is_default_for_unlisted' => true,
            'is_active' => true,
        ]);

        $selfEmployed = $this->makeEmployee('self_employed', $position);
        $item = $this->makeItem(100000);
        $item->employees()->attach($selfEmployed->id);

        $contractorItem = $this->makeItem(100000);
        $contractor = $this->makeContractor();
        $contractorItem->contractors()->attach($contractor->id, ['manual_amount_override' => 50000]);

        $result = PayrollCalculationService::calculate($this->workOrder->fresh());
        $rows = collect($result['rows']);

        // Самозанятому 500 ₽ + 6% компенсации налога = 530 ₽.
        $this->assertSame(53000, $rows->firstWhere('employee_id', $selfEmployed->id)['amount']);
        // Подрядчику — ровно оговоренная сумма, без гросс-апа.
        $this->assertSame(50000, $rows->firstWhere('client_id', $contractor->id)['amount']);
    }
}
