<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class Service extends Model
{
    use SoftDeletes, HasTranslations;

    protected $fillable = [
        'service_category_id', 'business_direction_id', 'name', 'price', 'prices', 'currency_id', 'duration_minutes', 'is_active'
    ];
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'prices' => 'array',
            'currency_id' => 'integer',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function businessDirection(): BelongsTo
    {
        return $this->belongsTo(BusinessDirection::class, 'business_direction_id');
    }
}