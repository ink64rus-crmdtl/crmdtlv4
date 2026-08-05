<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Exception;

class FinanceService
{
    /**
     * Проведение одиночной транзакции (Доход или Расход) с обновлением баланса счета.
     */
    public static function processTransaction(array $data, ?int $userId = null): Transaction
    {
        return DB::transaction(function () use ($data, $userId) {
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

            return Transaction::create([
                'account_id' => $account->id,
                'branch_id' => $data['branch_id'],
                'transaction_category_id' => $data['transaction_category_id'] ?? null,
                'payable_type' => $data['payable_type'] ?? null,
                'payable_id' => $data['payable_id'] ?? null,
                'type' => $data['type'],
                'amount' => $amount,
                'comment' => $data['comment'] ?? null,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Перевод средств между счетами (Создает две связанные транзакции).
     */
    public static function processTransfer(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId) {
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
                'amount' => $amount,
                'comment' => 'Перевод на счет: ' . $toAccount->name . (!empty($data['comment']) ? ' (' . $data['comment'] . ')' : ''),
                'created_by' => $userId,
            ]);

            $txIn = Transaction::create([
                'account_id' => $toAccount->id,
                'branch_id' => $data['branch_id'],
                'type' => 'transfer',
                'amount' => $amount,
                'comment' => 'Перевод со счета: ' . $fromAccount->name . (!empty($data['comment']) ? ' (' . $data['comment'] . ')' : ''),
                'created_by' => $userId,
            ]);

            return [$txOut, $txIn];
        });
    }

    /**
     * Отмена (удаление) транзакции с возвратом баланса.
     */
    public static function revertTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $account = Account::lockForUpdate()->findOrFail($transaction->account_id);

            if ($transaction->type === 'expense') {
                $account->balance += $transaction->amount;
            } elseif ($transaction->type === 'income') {
                $account->balance -= $transaction->amount;
            } elseif ($transaction->type === 'transfer') {
                // Для трансферов логика сложнее, пока просто возвращаем баланс на этот конкретный счет
                // В идеале нужно находить парную транзакцию и откатывать обе
                $account->balance += $transaction->amount; // Если это было списание (txOut), то возвращаем. Если зачисление - логика требует доработки.
            }

            $account->save();
            $transaction->delete();
        });
    }
}