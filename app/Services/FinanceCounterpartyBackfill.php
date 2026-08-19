<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\Payroll;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Одноразовый бэкфилл контрагента (counterparty_type/counterparty_id) для уже
 * проведённых транзакций, у которых контрагент однозначно выводится из payable:
 *   - оплата заказ-наряда (type=income, payable=WorkOrder)  → клиент заказа;
 *   - оплата приходной накладной (type=expense, payable=GoodsReceipt) → поставщик;
 *   - выплата ЗП (type=expense, payable=Payroll) → сотрудник или подрядчик.
 * Комиссия эквайринга (type=expense, payable=WorkOrder) сознательно НЕ трогается —
 * контрагента у неё нет и не будет.
 * Метод идемпотентен: работает только по строкам с NULL counterparty_type, поэтому
 * повторный вызов не перезаписывает уже проставленные значения.
 */
class FinanceCounterpartyBackfill
{
    public static function run(): void
    {
        // Оплата заказ-наряда (income) → клиент заказа
        DB::table('transactions')
            ->whereNull('counterparty_type')
            ->where('payable_type', WorkOrder::class)
            ->where('type', 'income')
            ->join('work_orders', 'work_orders.id', '=', 'transactions.payable_id')
            ->update([
                'counterparty_type' => Client::class,
                'counterparty_id' => DB::raw('work_orders.client_id'),
            ]);

        // Оплата приходной накладной (expense) → поставщик
        DB::table('transactions')
            ->whereNull('counterparty_type')
            ->where('payable_type', GoodsReceipt::class)
            ->where('type', 'expense')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'transactions.payable_id')
            ->update([
                'counterparty_type' => Client::class,
                'counterparty_id' => DB::raw('goods_receipts.supplier_id'),
            ]);

        // Выплата ЗП сотруднику (expense) → сотрудник
        DB::table('transactions')
            ->whereNull('counterparty_type')
            ->where('payable_type', Payroll::class)
            ->join('payrolls', 'payrolls.id', '=', 'transactions.payable_id')
            ->whereNotNull('payrolls.employee_id')
            ->update([
                'counterparty_type' => Employee::class,
                'counterparty_id' => DB::raw('payrolls.employee_id'),
            ]);

        // Выплата ЗП подрядчику (expense) → подрядчик (клиент)
        DB::table('transactions')
            ->whereNull('counterparty_type')
            ->where('payable_type', Payroll::class)
            ->join('payrolls', 'payrolls.id', '=', 'transactions.payable_id')
            ->whereNotNull('payrolls.client_id')
            ->update([
                'counterparty_type' => Client::class,
                'counterparty_id' => DB::raw('payrolls.client_id'),
            ]);
    }
}