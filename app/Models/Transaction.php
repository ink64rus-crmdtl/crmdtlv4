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
        'payable_type', 'payable_id', 'type', 'amount', 
        'currency_id', 'exchange_rate_snapshot', 'comment', 'created_by'
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency_id' => 'integer',
            'exchange_rate_snapshot' => 'decimal:6',
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
}