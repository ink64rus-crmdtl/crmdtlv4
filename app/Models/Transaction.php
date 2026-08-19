<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    protected $fillable = [
        'account_id', 'branch_id', 'transaction_category_id',
        'payable_type', 'payable_id', 'type', 'direction', 'amount', 'transaction_date',
        'currency_id', 'exchange_rate_snapshot', 'comment', 'created_by',
        'edited_at', 'edited_by', 'idempotency_key',
        'is_reconciled', 'reconciled_at', 'reconciled_by',
        'counterparty_type', 'counterparty_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency_id' => 'integer',
            'exchange_rate_snapshot' => 'decimal:6',
            'transaction_date' => 'date',
            'edited_at' => 'datetime',
            'is_reconciled' => 'boolean',
            'reconciled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Контрагент операции — Client или Employee (полиморф). Пуст у переводов
     * и комиссии эквайринга. У операций с payable контрагент выводится из него
     * и не редактируется вручную.
     */
    public function counterparty(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Человекочитаемое имя контрагента для списков (требует eager-loaded
     * counterparty). null — контрагента нет (перевод/комиссия/удалённая запись).
     */
    public function counterpartyLabel(): ?string
    {
        $counterparty = $this->counterparty;
        if (! $counterparty) {
            return null;
        }

        if ($counterparty instanceof Employee) {
            return trim(implode(' ', array_filter([
                $counterparty->last_name,
                $counterparty->first_name,
                $counterparty->middle_name,
            ]))) ?: 'Сотрудник #'.$counterparty->id;
        }

        return $counterparty->name ?? 'Клиент #'.$counterparty->id;
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}