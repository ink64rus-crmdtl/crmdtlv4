<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRule extends Model
{
    protected $fillable = [
        'position_id', 'service_id', 'type', 
        'fixed_amount', 'percentage_value', 'is_active'
    ];

    protected function casts(): array
    {
        return [
            'fixed_amount' => 'integer',
            'percentage_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}