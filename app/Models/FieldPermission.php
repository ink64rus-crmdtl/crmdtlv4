<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldPermission extends Model
{
    protected $fillable = [
        'role_id',
        'entity_type',
        'field_key',
        'can_view',
        'can_edit',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_edit' => 'boolean',
        ];
    }
}