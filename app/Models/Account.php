<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'type', 'currency_id', 'balance', 'is_active'
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'currency_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}