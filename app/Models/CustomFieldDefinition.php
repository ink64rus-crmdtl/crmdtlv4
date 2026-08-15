<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomFieldDefinition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entity_type',
        'key',
        'label',
        'type',
        'options',
        'is_required',
        'is_filterable',
        'is_visible_in_list',
        'use_in_templates',
        'sort_order',
        'validation_rules',
        'created_by',
        'module_id',
    ];

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'options' => 'array',
            'validation_rules' => 'array',
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_visible_in_list' => 'boolean',
            'use_in_templates' => 'boolean',
        ];
    }
}
