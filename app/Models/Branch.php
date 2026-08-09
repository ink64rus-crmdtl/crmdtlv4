<?php

namespace App\Models;

use App\Services\LegalEntityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Branch extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia;

    protected $fillable = [
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

    /**
     * Многие-ко-многим (не BelongsTo с 2027_01_28) — одна точка реально может
     * выставлять документы от нескольких юрлиц (ИП и ООО одновременно), и
     * одно юрлицо может обслуживать несколько точек. Конкретное юрлицо
     * КОНКРЕТНОГО заказа — WorkOrder.legal_entity_id, эта связь только про
     * "что вообще доступно для выбора на этой точке" (см.
     * LegalEntityController — привязка настраивается со стороны юрлица).
     */
    public function legalEntities(): BelongsToMany
    {
        return $this->belongsToMany(LegalEntity::class, 'branch_legal_entity');
    }

    /**
     * Обратная сторона Warehouse::branches() — без неё WarehouseResolver::resolveFor()
     * падал на $branch->warehouses() (BadMethodCallException) для любой точки в
     * режиме склада per_branch/mixed, из-за чего завершение ЛЮБОГО заказа с товарной
     * позицией было невозможно (StockService::deduct() никогда не вызывался).
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'branch_warehouse')->withPivot('priority')->withTimestamps();
    }

    /**
     * Активные точки, отфильтрованные по юрлицу, выбранному в верхнем сайдбаре
     * (LegalEntityContext). Используй для всех выпадающих списков "Точка" в формах —
     * это НЕ применяется как глобальный scope, чтобы страница управления точками
     * (Settings/Branches) по-прежнему показывала все точки независимо от юрлица.
     * Точка без единого привязанного юрлица (legalEntities пуст) показывается
     * всегда — она может работать вообще без юрлица (см. CLAUDE.md).
     */
    public function scopeForSelect(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->when(
                LegalEntityContext::current(),
                fn (Builder $q, int $legalEntityId) => $q->where(function (Builder $q2) use ($legalEntityId) {
                    $q2->whereHas('legalEntities', fn (Builder $q3) => $q3->where('legal_entities.id', $legalEntityId))
                        ->orDoesntHave('legalEntities');
                })
            );
    }

    /**
     * Логотип точки — коллекция 'logo' (singleFile), диск 'local' — как у
     * Document/DocumentTemplate. Публичный disk НЕ подходит: stancl/tenancy
     * суффиксирует ROOT дисков local/public под конкретного тенанта
     * (storage/tenant{id}/app/public/...), но НЕ трогает 'url' в
     * config/filesystems.php — сгенерированный публичный URL вёл бы мимо
     * реального файла (в central storage/app/public, где его нет) или, того
     * хуже, коллидировал бы с media той же id у другого тенанта. Поэтому
     * логотип, как и документы, отдаётся не прямой ссылкой на disk-URL, а
     * потоком через BranchController::logo() (см. logo_url ниже) — там
     * тенантский контекст уже гарантирован тем же middleware, что и у
     * остальных tenant-роутов. Ограничение jpg/png — и здесь
     * (acceptsMimeTypes), и в валидации BranchController::store()/update()
     * (двойная защита: одна на уровне пакета, другая — понятная ошибка
     * пользователю до попытки сохранения). Конверсия 'thumb' — синхронный
     * crop-fit 300×300 (nonQueued — единичная загрузка одного лого не
     * bulk-операция, аналогично generate() документов из CLAUDE.md п.6).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 300, 300)
            ->nonQueued();
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->getFirstMedia('logo') ? route('settings.branches.logo', $this->id) : null;
    }
}