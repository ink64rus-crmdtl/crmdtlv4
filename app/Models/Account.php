<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'legal_entity_id',
        'name',
        'type',
        'commission_percent',
        'bank_name',
        'bik',
        'account_number',
        'corr_account',
        'currency_id',
        'balance',
        'is_active',
        'is_default_for_invoicing',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'balance' => 'integer',
            'currency_id' => 'integer',
            'is_active' => 'boolean',
            'is_default_for_invoicing' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}