<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormLayout extends Model
{
    protected $fillable = [
        'entity_type',
        'name',
        'layout',
        'is_default',
        'role_id',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }
}