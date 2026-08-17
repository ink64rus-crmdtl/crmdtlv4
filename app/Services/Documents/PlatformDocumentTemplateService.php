<?php

namespace App\Services\Documents;

use App\Models\Central\PlatformDocumentTemplate;
use App\Models\DocumentTemplate;
use Illuminate\Support\Collection;

/**
 * Библиотека эталонных шаблонов документов по странам (central БД,
 * App\Http\Controllers\Central\Admin\PlatformDocumentTemplateController) —
 * читается ТОЛЬКО через tenancy()->central(...), тот же принцип, что у
 * DadataService/WappiProProvider: тенантский контроллер не обращается к
 * tenancy()->central() напрямую, только через этот сервис.
 *
 * import() — снепшот, НЕ живая ссылка: копия становится обычным тенантским
 * DocumentTemplate, дальнейшие правки библиотечного шаблона на уже
 * скопированные записи не влияют (тот же принцип, что и у остальных
 * "копирование, не привязка" решений в проекте — см. CLAUDE.md, п.12.6 про
 * Document::isStale() как контрпример: здесь копия УЖЕ данные тенанта,
 * отслеживать её устаревание платформа сознательно не должна).
 */
class PlatformDocumentTemplateService
{
    /**
     * @return Collection<int, PlatformDocumentTemplate>
     */
    public static function listForCurrentTenant(): Collection
    {
        $country = tenant('country_code');

        return tenancy()->central(fn () => PlatformDocumentTemplate::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('country_code')->orWhere('country_code', $country))
            ->orderBy('name')
            ->get(['id', 'country_code', 'name', 'entity_type', 'format']));
    }

    /**
     * Один шаблон библиотеки, доступный ИМЕННО этому тенанту — та же
     * фильтрация (is_active + страна), что и в listForCurrentTenant(), но
     * для одной записи по id. Используется предпросмотром и import() —
     * раньше import() доверял id, который прислал фронт, без проверки на
     * бэкенде, что тенант вообще имеет право видеть этот шаблон (чужая
     * страна/неактивный шаблон технически импортировались бы по прямому
     * запросу с угаданным id).
     */
    public static function findVisibleForCurrentTenant(int $id): PlatformDocumentTemplate
    {
        $country = tenant('country_code');

        return tenancy()->central(fn () => PlatformDocumentTemplate::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('country_code')->orWhere('country_code', $country))
            ->findOrFail($id));
    }

    /**
     * Рендер тела шаблона БЕЗ привязки к реальной записи (превью в
     * библиотеке — ни у central-админки, ни у тенанта на этапе просмотра
     * ДО импорта нет конкретного WorkOrder/Client и т.п., от которого можно
     * было бы построить настоящие плейсхолдеры). DocumentRenderer — чистая
     * строковая функция, реальной модели не требует: обычные {{ключ}}
     * подставляются пустотой, {{#if}}-блоки вырезаются как заведомо ложные
     * (см. DocumentRenderer::stripUnresolvedPlaceholders()) — предпросмотр
     * не претендует на точность с реальными суммами/условиями, только на
     * визуальную вёрстку/шрифт/разметку.
     */
    public static function renderPreview(PlatformDocumentTemplate $template): string
    {
        return (new DocumentRenderer)->render($template->body, [], []);
    }

    public static function import(int $id): DocumentTemplate
    {
        $source = self::findVisibleForCurrentTenant($id);

        $template = DocumentTemplate::create([
            'name' => $source->name,
            'entity_type' => $source->entity_type,
            'format' => $source->format,
            // Уже готовый HTML — DocxToHtmlConverter не перезапускается,
            // тело давно сконвертировано при сохранении в библиотеке.
            'body' => $source->body,
            'number_prefix' => '',
            'number_reset_yearly' => true,
            'is_active' => true,
        ]);

        if ($source->format === 'docx') {
            $sourceMediaPath = tenancy()->central(fn () => $source->getFirstMedia('source_file')?->getPath());

            if ($sourceMediaPath) {
                // preservingOriginal() ОБЯЗАТЕЛЕН: без него addMedia() из пути
                // ПЕРЕМЕЩАЕТ файл, а не копирует — исходник пропал бы из
                // central-библиотеки после первого же импорта.
                $template->addMedia($sourceMediaPath)->preservingOriginal()->toMediaCollection('source_file');
            }
        }

        return $template;
    }
}
