<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * format='docx' — шаблон изначально загружен как .docx-файл, при сохранении
 * сконвертирован в HTML один раз (App\Services\Documents\DocxToHtmlConverter)
 * и записан в то же поле body, что и у обычных html-шаблонов — на рендер
 * документов формат не влияет, body всегда HTML. Оригинальный .docx хранится
 * в коллекции 'source_file' только для скачивания/повторной загрузки, не
 * участвует в генерации.
 */
class DocumentTemplate extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'name',
        'entity_type',
        'format',
        'body',
        'number_prefix',
        'number_reset_yearly',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'number_reset_yearly' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('source_file')->useDisk('local')->singleFile();
    }
}