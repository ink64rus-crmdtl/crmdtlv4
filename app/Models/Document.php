<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Сгенерированный PDF хранится через spatie/laravel-medialibrary (пакет
 * уже стоял в проекте неиспользуемым) в коллекции 'file' — ровно один файл
 * на документ (см. DocumentGenerationService), а не отдельная колонка
 * file_path: свободные конверсии/диски уже даёт сам пакет.
 */
class Document extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'branch_id',
        'document_template_id',
        'documentable_type',
        'documentable_id',
        'number',
        'title',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * "Данные изменились с момента формирования" — НЕ перерендеривает PDF
     * заново (уже сформированный документ — зафиксированный во времени
     * артефакт, что реально отправили клиенту, меняться задним числом не
     * должно), только сравнивает даты: изменилась ли связанная запись
     * (суммы, позиции — see WorkOrderController::recalculateTotals(), там
     * update() сам двигает updated_at) или реквизиты юрлица филиала-
     * источника ПОСЛЕ того, как этот документ был сформирован. Требует
     * заранее eager-loaded documentable/branch.legalEntity — иначе тихий
     * N+1 на каждый документ в списке, поэтому не через $appends глобально,
     * а через ->append('is_stale') в местах, где эти связи уже подгружены
     * (DocumentController::index()/WorkOrderController::show()/ClientController::show()).
     */
    protected function isStale(): Attribute
    {
        return Attribute::make(
            get: function () {
                $changedAt = collect([
                    $this->documentable?->updated_at,
                    $this->branch?->legalEntity?->updated_at,
                ])->filter()->max();

                return $changedAt !== null && $changedAt->gt($this->created_at);
            }
        );
    }

    /**
     * Диск 'local' (не дефолтный 'public' пакета) — сгенерированные документы
     * содержат финансовые/персональные данные клиента, публичной ссылкой по
     * предсказуемому пути раздавать нельзя (тот же принцип, что у экспортов
     * в ExportEntitiesJob — Storage::disk('local')). Скачивание — только
     * через DocumentController::download() с проверкой прав/тенанта.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->useDisk('local')->singleFile();
    }
}