<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Central (landlord) БД — библиотека эталонных шаблонов документов по
 * странам (Фаза 16, /admin, гвард platform_admin). Схема и назначение полей
 * зеркалят тенантский App\Models\DocumentTemplate (format='docx' так же
 * конвертируется в HTML один раз при сохранении, App\Services\Documents\
 * DocxToHtmlConverter, дальше body — обычный HTML). country_code=null —
 * шаблон общий для всех стран. Копирование в тенанта — App\Services\
 * Documents\PlatformDocumentTemplateService::import() — снепшот, без живой
 * связи с этой записью.
 */
class PlatformDocumentTemplate extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'country_code',
        'name',
        'entity_type',
        'format',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('source_file')->useDisk('local')->singleFile();
    }
}
