<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LegalEntity extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'tax_id',
        'requisites',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requisites' => 'array',
    ];
}