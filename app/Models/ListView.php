<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListView extends Model
{
    protected $fillable = [
        'entity_type',
        'user_id',
        'name',
        'visible_columns',
        'filters',
        'sort',
        'is_shared',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'visible_columns' => 'array',
            'filters' => 'array',
            'sort' => 'array',
            'is_shared' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}