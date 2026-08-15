<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Воронка продаж (Фаза 17). НЕТ BranchScope намеренно: воронка — это
 * настройка тенанта (как Lookup или ProductCategory), а не операционная
 * запись под локацией. Изоляция по локации живёт на самой сделке (Deal).
 */
class Pipeline extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'business_direction_id',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function businessDirection(): BelongsTo
    {
        return $this->belongsTo(BusinessDirection::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
