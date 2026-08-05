<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountDailySnapshot;
use App\Models\Transaction;

class AccountSnapshotService
{
    /**
     * Пересчитывает дневной снэпшот одного счета за одну дату.
     *
     * Остаток на конец дня считается не цепочкой от предыдущего снэпшота (риск накопления ошибки
     * при пропущенных/повреждённых днях), а напрямую — сумма всех подписанных движений счета
     * по дату включительно. Это агрегат по индексу (account_id, transaction_date) для ОДНОГО
     * счета — не зависит от объема данных всей системы, дешево вызывать точечно при бэкдейтинге.
     */
    public static function recomputeForDate(int $accountId, string $date): void
    {
        if (!Account::whereKey($accountId)->exists()) {
            return;
        }

        $upToDate = Transaction::where('account_id', $accountId)
            ->whereDate('transaction_date', '<=', $date)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount WHEN type = 'transfer' AND direction = 'in' THEN amount ELSE 0 END), 0) as inflow,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount WHEN type = 'transfer' AND direction = 'out' THEN amount ELSE 0 END), 0) as outflow
            ")
            ->first();

        $closingBalance = (int) $upToDate->inflow - (int) $upToDate->outflow;

        $dayTotals = Transaction::where('account_id', $accountId)
            ->whereDate('transaction_date', $date)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income_total,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expense_total,
                COALESCE(SUM(CASE WHEN type = 'transfer' AND direction = 'in' THEN amount ELSE 0 END), 0) as transfer_in_total,
                COALESCE(SUM(CASE WHEN type = 'transfer' AND direction = 'out' THEN amount ELSE 0 END), 0) as transfer_out_total
            ")
            ->first();

        $netMovement = (int) $dayTotals->income_total - (int) $dayTotals->expense_total
            + (int) $dayTotals->transfer_in_total - (int) $dayTotals->transfer_out_total;

        AccountDailySnapshot::updateOrCreate(
            ['account_id' => $accountId, 'snapshot_date' => $date],
            [
                'opening_balance' => $closingBalance - $netMovement,
                'income_total' => (int) $dayTotals->income_total,
                'expense_total' => (int) $dayTotals->expense_total,
                'transfer_in_total' => (int) $dayTotals->transfer_in_total,
                'transfer_out_total' => (int) $dayTotals->transfer_out_total,
                'closing_balance' => $closingBalance,
            ]
        );
    }
}
