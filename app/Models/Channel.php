<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'provider', 'credentials', 'is_active'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', // Автоматическое шифрование токенов в БД
            'is_active' => 'boolean',
        ];
    }
}