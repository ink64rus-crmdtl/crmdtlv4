<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'legal_entity_id',
        'name',
        'address',
        'city',
        'phone',
        'timezone',
        'is_active',
        'working_hours',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'working_hours' => 'array',
    ];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}