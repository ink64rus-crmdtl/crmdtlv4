<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class ServiceCategory extends Model
{
    use SoftDeletes, HasTranslations;

    protected $fillable = ['name', 'business_direction_id', 'is_active'];
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function businessDirection(): BelongsTo
    {
        return $this->belongsTo(BusinessDirection::class, 'business_direction_id');
    }
}