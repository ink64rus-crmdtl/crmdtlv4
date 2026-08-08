<?php

namespace App\Models;

use App\Services\LegalEntityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'legal_entity_id',
        'name',
        'address',
        'city',
        'phone',
        'timezone',
        'is_active',
        'working_hours',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'working_hours' => 'array',
    ];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /**
     * Обратная сторона Warehouse::branches() — без неё WarehouseResolver::resolveFor()
     * падал на $branch->warehouses() (BadMethodCallException) для любого филиала в
     * режиме склада per_branch/mixed, из-за чего завершение ЛЮБОГО заказа с товарной
     * позицией было невозможно (StockService::deduct() никогда не вызывался).
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'branch_warehouse')->withPivot('priority')->withTimestamps();
    }

    /**
     * Активные филиалы, отфильтрованные по юрлицу, выбранному в верхнем сайдбаре
     * (LegalEntityContext). Используй для всех выпадающих списков "Филиал" в формах —
     * это НЕ применяется как глобальный scope, чтобы страница управления филиалами
     * (Settings/Branches) по-прежнему показывала филиалы всех юрлиц.
     */
    public function scopeForSelect(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->when(LegalEntityContext::current(), fn (Builder $q, int $legalEntityId) => $q->where('legal_entity_id', $legalEntityId));
    }
}