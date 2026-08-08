<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\FinanceService;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Exception;

class PayrollController extends Controller
{
    /**
     * Взаиморасчёты с сотрудниками (Фаза 10.4) — сколько каждому начислено,
     * выплачено, удержано штрафами и сколько ещё причитается ("баланс").
     * Баланс = ожидающие выплаты начисления минус ожидающие штрафы; штрафы
     * не выплачиваются деньгами напрямую (см. payout()), поэтому уменьшают
     * баланс сразу, как только созданы (пока их не отменили).
     */
    public function index(Request $request): Response
    {
        $query = Employee::with('position')->where('is_active', true);

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            ['first_name', 'last_name', 'phone'],
        );

        if (!$request->has('sort_by')) {
            $query->orderBy('last_name')->orderBy('first_name');
        }

        $employees = $query->paginate(20)->withQueryString();

        $sums = Payroll::whereIn('employee_id', $employees->getCollection()->pluck('id'))
            ->selectRaw("
                employee_id,
                SUM(CASE WHEN type = 'accrual' AND status != 'canceled' THEN amount ELSE 0 END) as accrued_total,
                SUM(CASE WHEN type = 'accrual' AND status = 'paid' THEN amount ELSE 0 END) as paid_total,
                SUM(CASE WHEN type = 'deduction' AND status = 'pending' THEN amount ELSE 0 END) as deductions_total
            ")
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $employees->getCollection()->transform(function (Employee $employee) use ($sums) {
            $row = $sums->get($employee->id);
            $accruedTotal = (int) ($row->accrued_total ?? 0);
            $paidTotal = (int) ($row->paid_total ?? 0);
            $deductionsTotal = (int) ($row->deductions_total ?? 0);

            return [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'accrued_total' => $accruedTotal,
                'paid_total' => $paidTotal,
                'deductions_total' => $deductionsTotal,
                'balance' => $accruedTotal - $paidTotal - $deductionsTotal,
            ];
        });

        return Inertia::render('HR/Payroll/Index', [
            'employees' => $employees,
            'filters' => $request->all(),
        ]);
    }

    /**
     * Ручное начисление/штраф (Фаза 10.3) — не привязано к заказу, role='manual'.
     * Оклад начисляется отдельно, автоматически — см. AccruePayrollSalaries.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', 'string', 'in:accrual,deduction'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        Payroll::create([
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'type' => $validated['type'],
            'role' => 'manual',
            'amount' => (int) round($validated['amount'] * 100),
            'status' => 'pending',
            'comment' => $validated['comment'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', $validated['type'] === 'accrual' ? 'Начисление добавлено' : 'Штраф добавлен');
    }

    /**
     * Фактическая выплата — списание с реальной кассы через FinanceService,
     * только для type=accrual (штрафы деньгами не выплачиваются, они уменьшают
     * будущие начисления — взаиморасчёты, Фаза 10.4).
     */
    public function payout(Request $request, Payroll $payroll)
    {
        if ($payroll->status !== 'pending') {
            return redirect()->back()->withErrors(['status' => 'Эта запись уже выплачена или отменена.']);
        }

        if ($payroll->type !== 'accrual') {
            return redirect()->back()->withErrors(['type' => 'Штраф не выплачивается деньгами напрямую — он учитывается при взаиморасчётах.']);
        }

        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $employee = $payroll->employee;

        try {
            DB::transaction(function () use ($payroll, $account, $employee) {
                $transaction = FinanceService::processTransaction([
                    'account_id' => $account->id,
                    'branch_id' => $payroll->branch_id,
                    'type' => 'expense',
                    'amount' => $payroll->amount,
                    'comment' => 'Выплата ЗП: ' . trim($employee->first_name . ' ' . $employee->last_name) . ($payroll->comment ? ' (' . $payroll->comment . ')' : ''),
                    'payable_type' => Payroll::class,
                    'payable_id' => $payroll->id,
                ], auth()->id());

                $payroll->update([
                    'status' => 'paid',
                    'paid_transaction_id' => $transaction->id,
                ]);
            });

            return redirect()->back()->with('success', 'Выплата проведена');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при выплате: ' . $e->getMessage()]);
        }
    }

    /**
     * Отмена — только для ещё не выплаченных начислений. Уже выплаченные
     * (реальная транзакция по кассе создана) через это действие не трогаем —
     * для отката потребовался бы FinanceService::revertTransaction(), это
     * сознательно вне MVP 10.3 (штатно откатывать выплаченную ЗП не должно
     * быть обычной операцией "в один клик").
     */
    public function cancel(Payroll $payroll)
    {
        if ($payroll->status !== 'pending') {
            return redirect()->back()->withErrors(['status' => 'Можно отменить только ещё не выплаченную запись.']);
        }

        $payroll->update(['status' => 'canceled']);

        return redirect()->back()->with('success', 'Запись отменена');
    }
}
