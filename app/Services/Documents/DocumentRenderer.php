<?php

namespace App\Services\Documents;

/**
 * Рендер тела шаблона документа. НЕ Blade и не любой другой движок,
 * исполняющий PHP — document_templates.body редактирует администратор
 * тенанта, а платформа мультитенантна (CLAUDE.md, п.0); Blade::render()
 * позволил бы через @php выполнить код на сервере. Вместо этого — свой
 * плоский {{key}}-синтаксис (тот же, что уже принят в
 * App\Services\Messaging\MessageTemplateService) плюс ОДИН неглубокий
 * повторяющийся блок {{#section}}...{{/section}} для табличных секций
 * (позиции заказа и т.п.) — чистая строковая подстановка, никакого
 * исполнения кода в принципе. Вложенные блоки не поддерживаются: ни один
 * из исследованных аналогов (amoCRM/Bitrix24/1С) не даёт больше одного
 * уровня таблицы в печатной форме — не единственный этой цели ради.
 *
 * Для шаблонов, сконвертированных из .docx (App\Services\Documents\
 * DocxToHtmlConverter), явные маркеры {{#section}}/{{/section}} неприменимы:
 * пользователь печатает текст только ВНУТРИ ячеек/абзацев Word, у него нет
 * способа вписать "сырой" маркер ровно на границе строки таблицы. Поэтому
 * при отсутствии явных маркеров — фолбэк: строка-образец находится по
 * первому плейсхолдеру секции внутри <tr>...</tr> автоматически, без
 * какого-либо специального синтаксиса от пользователя.
 */
class DocumentRenderer
{
    /**
     * @param string $body Тело шаблона
     * @param array<string,string> $flat Плоские плейсхолдеры ("tenant.name" => "ООО Ромашка")
     * @param array<string,array<int,array<string,string>>> $tables Табличные секции
     *   (["items" => [["item.name" => "Полировка", "item.total" => "1 200,00"], ...]])
     */
    public function render(string $body, array $flat, array $tables = []): string
    {
        $body = $this->renderTables($body, $tables);
        $body = $this->renderFlat($body, $flat);
        $body = $this->stripUnresolvedPlaceholders($body);

        return $this->forceCyrillicFont($body);
    }

    /**
     * dompdf резолвит любой НЕ-DejaVu font-family ("Arial", "Calibri" —
     * то, что реально пишет и голова пользователя в HTML-режиме, и Word
     * при конвертации .docx, см. DocxToHtmlConverter) в один из Base-14
     * PDF-шрифтов (installed-fonts.dist.json: sans-serif/Arial → Helvetica) —
     * а у них нет кириллических глифов вообще, без единой ошибки на любом
     * этапе, просто "?" вместо букв. В этой системе установлен только один
     * кириллический шрифт (DejaVu Sans, config/dompdf.php default_font) —
     * поэтому принудительно перебиваем font-family !important здесь, а не
     * полагаемся на то, что автор шаблона (или Word) его не укажет.
     */
    private function forceCyrillicFont(string $body): string
    {
        return '<style>*{font-family:"DejaVu Sans",sans-serif !important}</style>' . $body;
    }

    /**
     * Плейсхолдер может остаться в теле неподставленным, если конкретной
     * записи/юрлицу нечем его заполнить (например {{legal_entity.kpp}} у
     * ИП — в отличие от ООО, там просто нет такого ключа в requisites, см.
     * DocumentPlaceholderService::companyPlaceholders) — strtr() трогает
     * только известные ему ключи. Пользователю в готовом документе (счёт,
     * акт — то, что уходит клиенту) НИКОГДА не должен светиться сырой
     * "{{...}}" — тихая пустая строка тут правильнее сломанного текста.
     * Табличные секции ({{#...}}/{{/...}}) сюда не попадают — renderTables()
     * либо разворачивает их, либо (renderTableRowFallback) оставляет как
     * есть при полном отсутствии строк, что уже осознанный кейс.
     */
    private function stripUnresolvedPlaceholders(string $body): string
    {
        return preg_replace('/\{\{[a-z0-9_.]+\}\}/i', '', $body) ?? $body;
    }

    private function renderTables(string $body, array $tables): string
    {
        foreach ($tables as $section => $rows) {
            $pattern = '/\{\{#' . preg_quote($section, '/') . '\}\}(.*?)\{\{\/' . preg_quote($section, '/') . '\}\}/s';

            if (preg_match($pattern, $body)) {
                $body = preg_replace_callback($pattern, function ($matches) use ($rows) {
                    $rowTemplate = $matches[1];

                    return implode('', array_map(
                        fn (array $row) => $this->renderFlat($rowTemplate, $row),
                        $rows
                    ));
                }, $body) ?? $body;

                continue;
            }

            $body = $this->renderTableRowFallback($body, $rows);
        }

        return $body;
    }

    /**
     * @param array<int,array<string,string>> $rows
     */
    private function renderTableRowFallback(string $body, array $rows): string
    {
        if (empty($rows)) {
            return $body;
        }

        $sampleKey = array_key_first($rows[0]);
        $needle = preg_quote('{{' . $sampleKey . '}}', '/');
        // Ищем <tr>...</tr>, содержащий плейсхолдер строки-образца — с
        // отрицательным просмотром вперёд, чтобы не захватить соседние строки.
        $rowPattern = '/<tr\b[^>]*>(?:(?!<\/?tr\b).)*?' . $needle . '(?:(?!<\/?tr\b).)*?<\/tr>/is';

        if (!preg_match($rowPattern, $body, $rowMatch)) {
            return $body;
        }

        $rowTemplate = $rowMatch[0];
        $rendered = implode('', array_map(
            fn (array $row) => $this->renderFlat($rowTemplate, $row),
            $rows
        ));

        return str_replace($rowTemplate, $rendered, $body);
    }

    private function renderFlat(string $body, array $context): string
    {
        if (empty($context)) {
            return $body;
        }

        $escaped = array_map(
            fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            $context
        );

        return strtr($body, array_combine(
            array_map(fn ($key) => '{{' . $key . '}}', array_keys($escaped)),
            array_values($escaped)
        ));
    }
}
