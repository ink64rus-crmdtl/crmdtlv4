<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class FinanceService
{
    /**
     * Проведение одиночной транзакции (Доход или Расход) с обновлением баланса счета.
     * Поддерживает idempotency_key — повторная доставка того же вебхука не создаст дубликат.
     */
    public static function processTransaction(array $data, ?int $userId = null): Transaction
    {
        if (!empty($data['idempotency_key'])) {
            $existing = Transaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $transactionDate = $data['transaction_date'] ?? Carbon::now(TimezoneResolver::forBranch($data['branch_id'] ?? null))->toDateString();
        PeriodClosureService::assertNotClosed($transactionDate);

        return DB::transaction(function () use ($data, $userId, $transactionDate) {
            $account = Account::lockForUpdate()->findOrFail($data['account_id']);
            $amount = (int) $data['amount']; // Ожидается в копейках

            if ($data['type'] === 'expense') {
                $account->balance -= $amount;
            } elseif ($data['type'] === 'income') {
                $account->balance += $amount;
            } else {
                throw new Exception("Неизвестный тип транзакции: {$data['type']}");
            }

            $account->save();

            $transaction = Transaction::create([
                'account_id' => $account->id,
                'branch_id' => $data['branch_id'],
                'transaction_category_id' => $data['transaction_category_id'] ?? null,
                'payable_type' => $data['payable_type'] ?? null,
                'payable_id' => $data['payable_id'] ?? null,
                'type' => $data['type'],
                'amount' => $amount,
                'transaction_date' => $transactionDate,
                'comment' => $data['comment'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'created_by' => $userId,
            ]);

            self::recomputeSnapshotIfPast($account->id, $transactionDate);

            return $transaction;
        });
    }

    /**
     * Перевод средств между счетами (Создает две связанные транзакции).
     */
    public static function processTransfer(array $data, ?int $userId = null): array
    {
        $transactionDate = $data['transaction_date'] ?? Carbon::now(TimezoneResolver::forBranch($data['branch_id'] ?? null))->toDateString();
        PeriodClosureService::assertNotClosed($transactionDate);

        return DB::transaction(function () use ($data, $userId, $transactionDate) {
            $fromAccount = Account::lockForUpdate()->findOrFail($data['from_account_id']);
            $toAccount = Account::lockForUpdate()->findOrFail($data['to_account_id']);
            $amount = (int) $data['amount'];

            // Списание со счета-отправителя
            $fromAccount->balance -= $amount;
            $fromAccount->save();

            // Зачисление на счет-получатель
            $toAccount->balance += $amount;
            $toAccount->save();

            $txOut = Transaction::create([
                'account_id' => $fromAccount->id,
                'branch_id' => $data['branch_id'],
                'type' => 'transfer',
                'direction' => 'out',
                'amount' => $amount,
                'transaction_date' => $transactionDate,
                'comment' => 'Перевод на счет: ' . $toAccount->name . (!empty($data['comment']) ? ' (' . $data['comment'] . ')' : ''),
                'created_by' => $userId,
            ]);

            $txIn = Transaction::create([
                'account_id' => $toAccount->id,
                'branch_id' => $data['branch_id'],
                'type' => 'transfer',
                'direction' => 'in',
                'amount' => $amount,
                'transaction_date' => $transactionDate,
                'comment' => 'Перевод со счета: ' . $fromAccount->name . (!empty($data['comment']) ? ' (' . $data['comment'] . ')' : ''),
                'created_by' => $userId,
            ]);

            self::recomputeSnapshotIfPast($fromAccount->id, $transactionDate);
            self::recomputeSnapshotIfPast($toAccount->id, $transactionDate);

            return [$txOut, $txIn];
        });
    }

    /**
     * Отмена (удаление) транзакции с возвратом баланса.
     */
    public static function revertTransaction(Transaction $transaction): void
    {
        PeriodClosureService::assertNotClosed($transaction->transaction_date?->toDateString());

        DB::transaction(function () use ($transaction) {
            $account = Account::lockForUpdate()->findOrFail($transaction->account_id);
            $payable = $transaction->payable;
            $accountId = $transaction->account_id;
            $date = $transaction->transaction_date?->toDateString();

            if ($transaction->type === 'expense') {
                $account->balance += $transaction->amount;
            } elseif ($transaction->type === 'income') {
                $account->balance -= $transaction->amount;
            } elseif ($transaction->type === 'transfer') {
                // direction='out' — при проведении списали со счета, при откате возвращаем.
                // direction='in' — при проведении зачислили на счет, при откате списываем обратно.
                $account->balance += $transaction->direction === 'in' ? -$transaction->amount : $transaction->amount;
            }

            $account->save();
            $transaction->delete();

            if ($payable && method_exists($payable, 'syncPaymentStatus')) {
                $payable->syncPaymentStatus();
            }

            self::recomputeSnapshotIfPast($accountId, $date);
        });
    }

    /**
     * Редактирование уже проведенной транзакции (сумма, дата, комментарий, категория).
     * Баланс счета корректируется на разницу (O(1) — не требует пересчета других записей).
     * Смена счета/типа операции через этот метод не поддерживается (см. TransactionController::update).
     */
    public static function updateTransaction(Transaction $transaction, array $data, ?int $userId = null): Transaction
    {
        $currentDate = $transaction->transaction_date?->toDateString();
        PeriodClosureService::assertNotClosed($currentDate);

        $changingAmountOrDate = array_key_exists('amount', $data) || array_key_exists('transaction_date', $data);

        if ($transaction->is_reconciled && $changingAmountOrDate) {
            throw new Exception('Транзакция сверена с банковской выпиской — снимите отметку сверки, чтобы изменить сумму или дату.');
        }

        if (array_key_exists('transaction_date', $data) && $data['transaction_date'] !== $currentDate) {
            PeriodClosureService::assertNotClosed($data['transaction_date']);
        }

        return DB::transaction(function () use ($transaction, $data, $userId, $currentDate) {
            if (array_key_exists('amount', $data)) {
                $newAmount = (int) $data['amount'];

                if ($newAmount !== $transaction->amount) {
                    $account = Account::lockForUpdate()->findOrFail($transaction->account_id);
                    $delta = $newAmount - $transaction->amount;

                    if ($transaction->type === 'expense') {
                        $account->balance -= $delta;
                    } elseif ($transaction->type === 'transfer') {
                        $account->balance += $transaction->direction === 'in' ? $delta : -$delta;
                    } else {
                        $account->balance += $delta;
                    }

                    $account->save();
                    $transaction->amount = $newAmount;
                }
            }

            foreach (['transaction_date', 'comment', 'transaction_category_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $transaction->$field = $data[$field];
                }
            }

            $transaction->edited_at = now();
            $transaction->edited_by = $userId;
            $transaction->save();

            $payable = $transaction->payable;
            if ($payable && method_exists($payable, 'syncPaymentStatus')) {
                $payable->syncPaymentStatus();
            }

            $newDate = $transaction->transaction_date?->toDateString();
            self::recomputeSnapshotIfPast($transaction->account_id, $currentDate);
            if ($newDate !== $currentDate) {
                self::recomputeSnapshotIfPast($transaction->account_id, $newDate);
            }

            return $transaction;
        });
    }

    /**
     * Точечный пересчет дневного снэпшота, если дата операции уже в прошлом (т.е. для нее
     * мог существовать снэпшот, взятый ночной джобой). Для сегодняшних операций снэпшота еще
     * нет (появится в следующую полночь) — пересчитывать нечего.
     * "Прошлое"/"сегодня" определяется часовым поясом филиала счета, а не сервера (UTC) —
     * иначе для клиента в другом поясе граница дня съедет на несколько часов.
     */
    private static function recomputeSnapshotIfPast(?int $accountId, ?string $date): void
    {
        if (!$accountId || !$date) {
            return;
        }

        $account = Account::find($accountId);
        $timezone = TimezoneResolver::forBranch($account?->branch_id);

        if (Carbon::parse($date, $timezone)->lt(Carbon::now($timezone)->startOfDay())) {
            AccountSnapshotService::recomputeForDate($accountId, $date);
        }
    }
}
