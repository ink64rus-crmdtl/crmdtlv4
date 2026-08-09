<?php

namespace App\Services\Documents;

use PhpOffice\PhpWord\IOFactory;

/**
 * Конвертирует загруженный .docx ОДИН РАЗ, при сохранении шаблона — не при
 * каждой генерации документа. Результат кладётся в DocumentTemplate.body,
 * дальше это обычный HTML-шаблон на уже существующем синхронном пайплайне
 * (DocumentRenderer + dompdf, см. DocumentGenerationService) — генерация не
 * знает и не должна знать, что шаблон когда-то был файлом Word.
 *
 * Плейсхолдеры ({{...}}) при конвертации не трогаются — PhpWord переносит
 * текст ячеек/абзацев как обычный текст, реальную подстановку по-прежнему
 * делает DocumentRenderer.
 */
class DocxToHtmlConverter
{
    public static function convert(string $docxPath): string
    {
        $phpWord = IOFactory::load($docxPath, 'Word2007');

        $tmpPath = tempnam(sys_get_temp_dir(), 'docx2html_') . '.html';

        try {
            $writer = IOFactory::createWriter($phpWord, 'HTML');
            $writer->save($tmpPath);

            return file_get_contents($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    }
}
