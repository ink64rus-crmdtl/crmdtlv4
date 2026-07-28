<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Service extends Model
{
    use SoftDeletes, HasTranslations;

    protected $fillable = [
        'service_category_id', 'name', 'price', 'currency_id', 'duration_minutes', 'is_active'
    ];
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'currency_id' => 'integer',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}