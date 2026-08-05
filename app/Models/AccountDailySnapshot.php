<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDailySnapshot extends Model
{
    protected $fillable = [
        'account_id',
        'snapshot_date',
        'opening_balance',
        'income_total',
        'expense_total',
        'transfer_in_total',
        'transfer_out_total',
        'closing_balance',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'opening_balance' => 'integer',
            'income_total' => 'integer',
            'expense_total' => 'integer',
            'transfer_in_total' => 'integer',
            'transfer_out_total' => 'integer',
            'closing_balance' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
