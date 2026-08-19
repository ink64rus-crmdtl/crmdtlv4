<?php

namespace Tests\Agent;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\FinanceCounterpartyBackfill;
use App\Services\FinanceService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Фаза А финансовой доработки: контрагент (полиморф counterparty_type/counterparty_id)
 * и системные статьи транзакций.
 *
 * Ключевые инварианты, которые здесь проверяем:
 *  1. Ручной доход/расход БЕЗ основания требует контрагента (нельзя провести
 *     анонимную операцию мимо клиента/сотрудника — иначе в отчётах появится
 *     «пустое» движение денег).
 *  2. У операций с основанием контрагент выводится из документа и молча
 *     игнорируется при редактировании (нельзя рассинхронизировать кассу
 *     с заказом/начислением).
 *  3. Системные статьи (order_payment, payroll_payment, ...) сидятся миграцией,
 *     используются как дефолт в типовых операциях и полностью заблокированы
 *     от правки/удаления (паттерн Lookup.is_system).
 *  4. Бэкфилл (FinanceCounterpartyBackfill) заполняет старые операции по
 *     payable-ссылкам и идемпотентен; комиссия эквайринга (expense c
 *     payable=WorkOrder) контрагента не получает — это осознанная граница.
 *
 * HTTP-тесты идут через TenantHttpTestCase (реальный проход через роуты
 * тенанта) — так проверяются и валидация контроллера, и BranchScope, и
 * сквозной путь «форма → транзакция → баланс счёта».
 */
class TransactionCounterpartyTest extends TenantHttpTestCase
{
    private Branch $branch;

    private Branch $otherBranch;

    private Account $account;

    private Account $secondAccount;

    private Client $client;

    private Client $otherClient;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация КТ']);
        $this->otherBranch = Branch::create(['name' => 'Другая локация КТ']);
        $this->account = Account::create([
            'branch_id' => $this->branch->id,
            'type' => 'cash',
            'name' => 'Касса',
            'is_active' => true,
            'balance' => 1000000,
        ]);
        $this->secondAccount = Account::create([
            'branch_id' => $this->branch->id,
            'type' => 'cash',
            'name' => 'Касса 2',
            'is_active' => true,
            'balance' => 1000000,
        ]);
        $this->client = Client::create([
            'branch_id' => $this->branch->id,
            'type' => 'b2c',
            'name' => 'Клиент КТ',
        ]);
        $this->otherClient = Client::create([
            'branch_id' => $this->otherBranch->id,
            'type' => 'b2c',
            'name' => 'Клиент другой локации',
        ]);
        $position = Position::create(['name' => 'Мастер', 'is_active' => true]);
        $this->employee = Employee::create([
            'branch_id' => $this->branch->id,
            'position_id' => $position->id,
            'type' => 'staff',
            'first_name' => 'Иван',
            'last_name' => 'Тестов',
            'is_active' => true,
        ]);

        // Admin — единственная роль, для которой BranchScope не фильтрует
        // (иначе фабричный юзер без scopes увидел бы пустое множество записей)
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
    }

    private function makeRegularCategory(string $type = 'income'): TransactionCategory
    {
        return TransactionCategory::create([
            'name' => ['ru' => 'Тестовая статья '.uniqid()],
            'type' => $type,
            'is_active' => true,
        ]);
    }

    private function makeWorkOrder(?Branch $branch = null, ?Client $client = null, int $finalAmount = 50000): WorkOrder
    {
        return WorkOrder::create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'client_id' => ($client ?? $this->client)->id,
            'status' => 'in_progress',
            'payment_status' => 'unpaid',
            'total_amount' => $finalAmount,
            'discount_amount' => 0,
            'final_amount' => $finalAmount,
            'discount_is_manual' => true,
            'admin_assignment_mode' => 'manual',
        ]);
    }

    private function lastTransaction(): Transaction
    {
        return Transaction::query()->orderByDesc('id')->first();
    }

    // --- Ручные операции: контрагент обязателен без основания ---

    #[Test]
    public function manual_income_without_counterparty_rejected(): void
    {
        $category = $this->makeRegularCategory('income');

        $response = $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'transaction_category_id' => $category->id,
            'amount' => 100.00,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1000000, $this->account->fresh()->balance);
    }

    #[Test]
    public function manual_income_with_client_counterparty_created(): void
    {
        $category = $this->makeRegularCategory('income');

        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'transaction_category_id' => $category->id,
            'amount' => 150.50,
            'counterparty_type' => Client::class,
            'counterparty_id' => $this->client->id,
        ])->assertSessionHasNoErrors();

        $transaction = $this->lastTransaction();
        $this->assertSame('income', $transaction->type);
        $this->assertSame(Client::class, $transaction->counterparty_type);
        $this->assertSame($this->client->id, $transaction->counterparty_id);
        $this->assertSame($category->id, $transaction->transaction_category_id);
        $this->assertSame(1000000 + 15050, $this->account->fresh()->balance);
        $this->assertNull($transaction->payable_type);
        $this->assertNull($transaction->payable_id);
    }

    #[Test]
    public function manual_expense_with_employee_counterparty_created(): void
    {
        $category = $this->makeRegularCategory('expense');

        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'expense',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'transaction_category_id' => $category->id,
            'amount' => 200.00,
            'counterparty_type' => Employee::class,
            'counterparty_id' => $this->employee->id,
        ])->assertSessionHasNoErrors();

        $transaction = $this->lastTransaction();
        $this->assertSame('expense', $transaction->type);
        $this->assertSame(Employee::class, $transaction->counterparty_type);
        $this->assertSame($this->employee->id, $transaction->counterparty_id);
    }

    #[Test]
    public function counterparty_from_other_branch_rejected(): void
    {
        $category = $this->makeRegularCategory('income');

        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'transaction_category_id' => $category->id,
            'amount' => 100.00,
            'counterparty_type' => Client::class,
            'counterparty_id' => $this->otherClient->id,
        ])->assertSessionHasErrors('error');

        $this->assertSame(0, Transaction::query()->count());
    }

    // --- Переводы: контрагента нет и не принимается ---

    #[Test]
    public function transfer_ignores_counterparty(): void
    {
        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'transfer',
            'from_account_id' => $this->account->id,
            'to_account_id' => $this->secondAccount->id,
            'branch_id' => $this->branch->id,
            'amount' => 300.00,
            'counterparty_type' => Client::class,
            'counterparty_id' => $this->client->id,
        ])->assertSessionHasNoErrors();

        $transactions = Transaction::query()->where('type', 'transfer')->get();
        $this->assertCount(2, $transactions);
        $this->assertTrue($transactions->every(fn (Transaction $t) => $t->counterparty_type === null && $t->counterparty_id === null));
    }

    // --- Основание: привязка ручной оплаты к заказ-наряду ---

    #[Test]
    public function manual_order_payment_links_order_and_auto_fills_counterparty_and_category(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'amount' => 500.00,
            'work_order_id' => $workOrder->id,
        ])->assertSessionHasNoErrors();

        $transaction = $this->lastTransaction();
        $this->assertSame(WorkOrder::class, $transaction->payable_type);
        $this->assertSame($workOrder->id, $transaction->payable_id);
        $this->assertSame(Client::class, $transaction->counterparty_type);
        $this->assertSame($this->client->id, $transaction->counterparty_id);
        $this->assertSame(TransactionCategory::systemId('order_payment'), $transaction->transaction_category_id);

        $this->assertSame('paid', $workOrder->fresh()->payment_status);
    }

    #[Test]
    public function manual_order_payment_overpay_rejected(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'amount' => 600.00,
            'work_order_id' => $workOrder->id,
        ])->assertSessionHasErrors('error');

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('unpaid', $workOrder->fresh()->payment_status);
    }

    #[Test]
    public function manual_expense_linked_to_order_rejected(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'expense',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'amount' => 100.00,
            'work_order_id' => $workOrder->id,
        ])->assertSessionHasErrors('error');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function manual_order_payment_from_other_branch_rejected(): void
    {
        $workOrder = $this->makeWorkOrder($this->otherBranch, $this->otherClient);

        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'amount' => 100.00,
            'work_order_id' => $workOrder->id,
        ])->assertSessionHasErrors('error');

        $this->assertSame(0, Transaction::query()->count());
    }

    // --- Редактирование контрагента ---

    #[Test]
    public function counterparty_editable_on_free_transaction(): void
    {
        $category = $this->makeRegularCategory('income');
        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'transaction_category_id' => $category->id,
            'amount' => 100.00,
            'counterparty_type' => Client::class,
            'counterparty_id' => $this->client->id,
        ]);
        $transaction = $this->lastTransaction();

        $this->put($this->tenantUrl('/finance/transactions/'.$transaction->id), [
            'counterparty_type' => Employee::class,
            'counterparty_id' => $this->employee->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(Employee::class, $transaction->fresh()->counterparty_type);
        $this->assertSame($this->employee->id, $transaction->fresh()->counterparty_id);
    }

    #[Test]
    public function counterparty_edit_ignored_when_payable_exists(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->post($this->tenantUrl('/finance/transactions'), [
            'type' => 'income',
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'amount' => 500.00,
            'work_order_id' => $workOrder->id,
        ]);
        $transaction = $this->lastTransaction();

        $this->put($this->tenantUrl('/finance/transactions/'.$transaction->id), [
            'counterparty_type' => Employee::class,
            'counterparty_id' => $this->employee->id,
        ])->assertSessionHasNoErrors();

        $fresh = $transaction->fresh();
        $this->assertSame(Client::class, $fresh->counterparty_type);
        $this->assertSame($this->client->id, $fresh->counterparty_id);
    }

    // --- Системные статьи ---

    #[Test]
    public function system_categories_seeded_by_migration(): void
    {
        foreach (['order_payment', 'client_deposit', 'other_income', 'purchase_payment', 'payroll_payment', 'refund', 'commission', 'other_expense'] as $value) {
            $category = TransactionCategory::systemId($value);
            $this->assertNotNull($category, "Системная статья '{$value}' не найдена");
            $this->assertSame($value, TransactionCategory::find($category)->value);
            $this->assertTrue(TransactionCategory::find($category)->is_system);
        }
    }

    #[Test]
    public function system_category_update_and_destroy_blocked(): void
    {
        $systemCategory = TransactionCategory::find(TransactionCategory::systemId('order_payment'));
        $regularCategory = $this->makeRegularCategory();

        $this->put($this->tenantUrl('/finance/categories/'.$systemCategory->id), [
            'name' => 'Переименованная',
            'type' => 'income',
        ])->assertSessionHasErrors('error');

        $this->delete($this->tenantUrl('/finance/categories/'.$systemCategory->id))
            ->assertSessionHasErrors('error');

        $this->assertNotNull(TransactionCategory::withTrashed()->find($systemCategory->id));
        $this->assertNotNull($regularCategory->fresh());
    }

    #[Test]
    public function bulk_delete_rejects_system_category(): void
    {
        $systemCategory = TransactionCategory::find(TransactionCategory::systemId('order_payment'));
        $regularCategory = $this->makeRegularCategory();

        $this->post($this->tenantUrl('/finance/categories/bulk-delete'), [
            'ids' => [$systemCategory->id, $regularCategory->id],
        ])->assertSessionHasErrors('error');

        $this->assertNotNull($regularCategory->fresh(), 'Обычная статья не должна удалиться вместе с отклонённым bulk-запросом');
        $this->assertNotNull(TransactionCategory::withTrashed()->find($systemCategory->id));
    }

    // --- Авто-заполнение в типовых потоках ---

    #[Test]
    public function process_payment_sets_counterparty_and_system_category(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->post($this->tenantUrl('/operations/work-orders/'.$workOrder->id.'/payment'), [
            'account_id' => $this->account->id,
            'amount' => 500.00,
        ])->assertSessionHasNoErrors();

        $transaction = $this->lastTransaction();
        $this->assertSame(Client::class, $transaction->counterparty_type);
        $this->assertSame($this->client->id, $transaction->counterparty_id);
        $this->assertSame(TransactionCategory::systemId('order_payment'), $transaction->transaction_category_id);
    }

    #[Test]
    public function payout_sets_counterparty_for_employee(): void
    {
        $payroll = Payroll::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'type' => 'accrual',
            'role' => 'manual',
            'amount' => 50000,
            'status' => 'pending',
        ]);

        $this->post($this->tenantUrl('/hr/payroll/'.$payroll->id.'/payout'), [
            'account_id' => $this->account->id,
        ])->assertSessionHasNoErrors();

        $transaction = $this->lastTransaction();
        $this->assertSame(Employee::class, $transaction->counterparty_type);
        $this->assertSame($this->employee->id, $transaction->counterparty_id);
        $this->assertSame(TransactionCategory::systemId('payroll_payment'), $transaction->transaction_category_id);
        $this->assertSame('paid', $payroll->fresh()->status);
    }

    #[Test]
    public function payout_sets_counterparty_for_contractor(): void
    {
        $payroll = Payroll::create([
            'client_id' => $this->client->id,
            'branch_id' => $this->branch->id,
            'type' => 'accrual',
            'role' => 'manual',
            'amount' => 50000,
            'status' => 'pending',
        ]);

        $this->post($this->tenantUrl('/hr/payroll/'.$payroll->id.'/payout'), [
            'account_id' => $this->account->id,
        ])->assertSessionHasNoErrors();

        $transaction = $this->lastTransaction();
        $this->assertSame(Client::class, $transaction->counterparty_type);
        $this->assertSame($this->client->id, $transaction->counterparty_id);
        $this->assertSame(TransactionCategory::systemId('payroll_payment'), $transaction->transaction_category_id);
    }

    #[Test]
    public function commission_transaction_has_no_counterparty(): void
    {
        $acquiring = Account::create([
            'branch_id' => $this->branch->id,
            'type' => 'acquiring',
            'name' => 'Эквайринг',
            'is_active' => true,
            'commission_percent' => 10,
            'balance' => 1000000,
        ]);
        $workOrder = $this->makeWorkOrder();

        $this->post($this->tenantUrl('/operations/work-orders/'.$workOrder->id.'/payment'), [
            'account_id' => $acquiring->id,
            'amount' => 500.00,
        ])->assertSessionHasNoErrors();

        $commission = Transaction::query()->where('type', 'expense')->where('payable_type', WorkOrder::class)->first();
        $this->assertNotNull($commission);
        $this->assertSame(5000, $commission->amount);
        $this->assertNull($commission->counterparty_type);
        $this->assertNull($commission->counterparty_id);
        $this->assertSame(TransactionCategory::systemId('commission'), $commission->transaction_category_id);
    }

    // --- Бэкфилл старых операций ---

    #[Test]
    public function backfill_fills_counterparty_from_payable_and_is_idempotent(): void
    {
        $workOrder = $this->makeWorkOrder();
        $payroll = Payroll::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'type' => 'accrual',
            'role' => 'manual',
            'amount' => 50000,
            'status' => 'pending',
        ]);

        // «Легаси»-транзакции: без контрагента и без категории, только payable-ссылки
        FinanceService::processTransaction([
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'type' => 'income',
            'amount' => 50000,
            'comment' => 'Старая оплата заказа',
            'payable_type' => WorkOrder::class,
            'payable_id' => $workOrder->id,
        ]);
        FinanceService::processTransaction([
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'type' => 'expense',
            'amount' => 50000,
            'comment' => 'Старая выплата ЗП',
            'payable_type' => Payroll::class,
            'payable_id' => $payroll->id,
        ]);
        // Комиссия: expense c payable=WorkOrder, контрагента не имеет в принципе
        FinanceService::processTransaction([
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'type' => 'expense',
            'amount' => 5000,
            'comment' => 'Старая комиссия',
            'payable_type' => WorkOrder::class,
            'payable_id' => $workOrder->id,
        ]);

        FinanceCounterpartyBackfill::run();

        $income = Transaction::query()->where('comment', 'Старая оплата заказа')->first();
        $this->assertSame(Client::class, $income->counterparty_type);
        $this->assertSame($this->client->id, $income->counterparty_id);

        $payout = Transaction::query()->where('comment', 'Старая выплата ЗП')->first();
        $this->assertSame(Employee::class, $payout->counterparty_type);
        $this->assertSame($this->employee->id, $payout->counterparty_id);

        $commission = Transaction::query()->where('comment', 'Старая комиссия')->first();
        $this->assertNull($commission->counterparty_type);
        $this->assertNull($commission->counterparty_id);

        // Идемпотентность: повторный прогон ничего не ломает
        FinanceCounterpartyBackfill::run();
        $this->assertSame(Client::class, $income->fresh()->counterparty_type);
        $this->assertSame(Employee::class, $payout->fresh()->counterparty_type);
        $this->assertNull($commission->fresh()->counterparty_type);
    }
}
