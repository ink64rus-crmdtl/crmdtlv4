<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePeriodClosure extends Model
{
    protected $fillable = [
        'period_end_date',
        'closed_by',
        'closed_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'period_end_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
