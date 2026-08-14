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
 *
 * Условный блок {{#if key}}...{{/if}} — показывает кусок HTML, только если
 * значение key в контексте непустое (та же truthy-конвенция, что уже
 * неявно действует для опциональных плейсхолдеров вида {{work_order.
 * vat_rate}}, которые сегодня просто приходят пустой строкой, если
 * недоступны, — см. DocumentPlaceholderService). Нужен для шаблонов,
 * которые должны одинаково верно печататься в нескольких взаимоисключающих
 * состояниях одной записи (например НДС у юрлица заказа: не плательщик /
 * включён в цену / начисляется сверх) — DocumentPlaceholderService заранее
 * готовит нужный набор взаимоисключающих флагов, автор шаблона просто
 * оборачивает нужный кусок в нужный флаг. Один уровень, без вложенности и
 * БЕЗ отрицания/операторов сравнения — намеренно, тот же принцип "никакого
 * исполнения кода", что и у {{#section}} выше.
 */
class DocumentRenderer
{
    /**
     * @param  string  $body  Тело шаблона
     * @param  array<string,string>  $flat  Плоские плейсхолдеры ("tenant.name" => "ООО Ромашка")
     * @param  array<string,array<int,array<string,string>>>  $tables  Табличные секции
     *                                                                 (["items" => [["item.name" => "Полировка", "item.total" => "1 200,00"], ...]])
     */
    public function render(string $body, array $flat, array $tables = []): string
    {
        $body = $this->renderTables($body, $tables);
        // Второй проход по всему телу — тем же принципом, что уже действует
        // для renderFlat() ниже: условия ВНУТРИ строк таблицы уже разрешены
        // локальным контекстом строки (см. renderTables()/
        // renderTableRowFallback()), этот проход добирает условия вне
        // таблицы и условия внутри строк, ссылающиеся на общий контекст
        // (например {{#if work_order.vat_exclusive}} внутри {{#items}}).
        $body = $this->renderConditionals($body, $flat);
        $body = $this->renderFlat($body, $flat);
        $body = $this->stripUnresolvedPlaceholders($body);

        return $this->forceCyrillicFont($body);
    }

    /**
     * Резолвит только условия, чей ключ РЕАЛЬНО присутствует в $context —
     * если ключа нет (например условие внутри строки таблицы ссылается на
     * общий контекст заказа, а не на данные самой строки), маркер
     * оставляется как есть для следующего, более широкого прохода (см.
     * render()/renderTables()). Тем же принципом уже работает renderFlat()
     * ниже — strtr() трогает только известные ему ключи. Действительно
     * неизвестный ключ (опечатка, чужой тип сущности) в итоге подчищается
     * safety-net'ом в stripUnresolvedPlaceholders() — как "ложь" по умолчанию.
     *
     * @param  array<string,string>  $context
     */
    private function renderConditionals(string $body, array $context): string
    {
        return preg_replace_callback(
            '/\{\{#if\s+([a-z0-9_.]+)\}\}(.*?)\{\{\/if\}\}/is',
            function ($m) use ($context) {
                if (! array_key_exists($m[1], $context)) {
                    return $m[0];
                }

                return ! empty($context[$m[1]]) ? $m[2] : '';
            },
            $body
        ) ?? $body;
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
        return '<style>*{font-family:"DejaVu Sans",sans-serif !important}</style>'.$body;
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
        // Условие, чей ключ не нашёлся НИ в одном из пройденных контекстов
        // (опечатка в ключе, условие для чужого типа сущности) — safety-net:
        // блок целиком считается ложным и вырезается вместе с содержимым,
        // а не показывается клиенту как есть.
        $body = preg_replace_callback(
            '/\{\{#if\s+[a-z0-9_.]+\}\}(.*?)\{\{\/if\}\}/is',
            fn () => '',
            $body
        ) ?? $body;

        // Незакрытый маркер (без парного {{/if}}) описанным выше проходом не
        // ловится — подчищаем сам маркер отдельно; сырой "{{...}}" никогда
        // не должен попасть в документ, уходящий клиенту.
        $body = preg_replace('/\{\{#if\s+[a-z0-9_.]+\}\}|\{\{\/if\}\}/i', '', $body) ?? $body;

        return preg_replace('/\{\{[a-z0-9_.]+\}\}/i', '', $body) ?? $body;
    }

    private function renderTables(string $body, array $tables): string
    {
        foreach ($tables as $section => $rows) {
            $pattern = '/\{\{#'.preg_quote($section, '/').'\}\}(.*?)\{\{\/'.preg_quote($section, '/').'\}\}/s';

            if (preg_match($pattern, $body)) {
                $body = preg_replace_callback($pattern, function ($matches) use ($rows) {
                    $rowTemplate = $matches[1];

                    return implode('', array_map(
                        fn (array $row) => $this->renderFlat($this->renderConditionals($rowTemplate, $row), $row),
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
     * @param  array<int,array<string,string>>  $rows
     */
    private function renderTableRowFallback(string $body, array $rows): string
    {
        if (empty($rows)) {
            return $body;
        }

        $sampleKey = array_key_first($rows[0]);
        $needle = preg_quote('{{'.$sampleKey.'}}', '/');
        // Ищем <tr>...</tr>, содержащий плейсхолдер строки-образца — с
        // отрицательным просмотром вперёд, чтобы не захватить соседние строки.
        $rowPattern = '/<tr\b[^>]*>(?:(?!<\/?tr\b).)*?'.$needle.'(?:(?!<\/?tr\b).)*?<\/tr>/is';

        if (! preg_match($rowPattern, $body, $rowMatch)) {
            return $body;
        }

        $rowTemplate = $rowMatch[0];
        $rendered = implode('', array_map(
            fn (array $row) => $this->renderFlat($this->renderConditionals($rowTemplate, $row), $row),
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
            array_map(fn ($key) => '{{'.$key.'}}', array_keys($escaped)),
            array_values($escaped)
        ));
    }
}
