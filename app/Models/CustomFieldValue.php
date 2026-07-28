<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldValue extends Model
{
    protected $fillable = [
        'custom_field_definition_id',
        'entity_type',
        'entity_id',
        'value',
        'value_text',
        'value_number',
        'value_date',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'value_number' => 'decimal:4',
            'value_date' => 'datetime',
        ];
    }
}