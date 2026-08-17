<?php

namespace Tests\Agent;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\Transaction;
use App\Services\FinanceService;
use Exception;
use PHPUnit\Framework\Attributes\Test;

/**
 * Откат выплаченной ЗП/взаиморасчётов с подрядчиками (статус paid).
 *
 * Инвариант: выплата — это НЕРАЗРЫВНАЯ пара «expense-транзакция по кассе +
 * status=paid + paid_transaction_id». Откат обязан вернуть ОБЕ половины:
 * деньги в кассу (FinanceService::revertTransaction) и начисление в 'pending'
 * (чтобы его можно было выплатить заново), иначе взаиморасчёты начнут считать
 * долг погашенным, хотя деньги уже вернулись — прямой финансовый рассинхрон.
 */
class PayrollReversePayoutTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Position $position;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
        $this->position = Position::create(['name' => 'Мастер', 'is_active' => true]);
        $this->account = Account::create([
            'branch_id' => $this->branch->id,
            'type' => 'cash',
            'name' => 'Касса',
            'is_active' => true,
            'balance' => 100000,
        ]);
    }

    private function makeEmployee(): Employee
    {
        return Employee::create([
            'branch_id' => $this->branch->id,
            'position_id' => $this->position->id,
            'type' => 'staff',
            'first_name' => 'Иван',
            'last_name' => 'Тестов',
            'is_active' => true,
        ]);
    }

    private function makeContractor(): Client
    {
        return Client::create([
            'branch_id' => $this->branch->id,
            'type' => 'b2b',
            'name' => 'ООО Подрядчик',
        ]);
    }

    private function makePayroll(?int $employeeId = null, ?int $clientId = null, int $amount = 50000): Payroll
    {
        return Payroll::create([
            'employee_id' => $employeeId,
            'client_id' => $clientId,
            'branch_id' => $this->branch->id,
            'type' => 'accrual',
            'role' => 'manual',
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    /**
     * Проводит выплату так же, как PayrollController::payOne(): expense-транзакция
     * с payable=Payroll + status=paid + paid_transaction_id.
     */
    private function pay(Payroll $payroll): Transaction
    {
        $transaction = FinanceService::processTransaction([
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'type' => 'expense',
            'amount' => $payroll->amount,
            'comment' => 'Выплата ЗП',
            'payable_type' => Payroll::class,
            'payable_id' => $payroll->id,
        ]);

        $payroll->update(['status' => 'paid', 'paid_transaction_id' => $transaction->id]);

        return $transaction;
    }

    #[Test]
    public function reverting_payout_returns_payroll_to_pending_and_restores_balance(): void
    {
        $employee = $this->makeEmployee();
        $payroll = $this->makePayroll(employeeId: $employee->id);
        $transaction = $this->pay($payroll);

        $this->assertSame(50000, $this->account->fresh()->balance);

        FinanceService::revertTransaction($transaction);

        $this->assertSame(100000, $this->account->fresh()->balance, 'Баланс кассы должен вернуться');
        $this->assertNull(Transaction::find($transaction->id), 'Транзакция должна быть удалена');

        $payroll = $payroll->fresh();
        $this->assertSame('pending', $payroll->status, 'Начисление должно вернуться в "ожидает"');
        $this->assertNull($payroll->paid_transaction_id);
    }

    #[Test]
    public function reverting_contractor_payout_is_symmetric(): void
    {
        $contractor = $this->makeContractor();
        $payroll = $this->makePayroll(clientId: $contractor->id, amount: 30000);
        $transaction = $this->pay($payroll);

        FinanceService::revertTransaction($transaction);

        $payroll = $payroll->fresh();
        $this->assertSame('pending', $payroll->status);
        $this->assertNull($payroll->paid_transaction_id);
        $this->assertSame(100000, $this->account->fresh()->balance);
    }

    #[Test]
    public function sync_payment_status_ignores_non_paid_statuses(): void
    {
        $employee = $this->makeEmployee();
        $payroll = $this->makePayroll(employeeId: $employee->id);

        $payroll->syncPaymentStatus();
        $this->assertSame('pending', $payroll->fresh()->status);

        $payroll->update(['status' => 'canceled']);
        $payroll->syncPaymentStatus();
        $this->assertSame('canceled', $payroll->fresh()->status);
    }

    #[Test]
    public function reverting_reconciled_transaction_is_blocked(): void
    {
        $employee = $this->makeEmployee();
        $payroll = $this->makePayroll(employeeId: $employee->id);
        $transaction = $this->pay($payroll);

        $transaction->update(['is_reconciled' => true, 'reconciled_at' => now()]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('сверена');

        FinanceService::revertTransaction($transaction);
    }
}
