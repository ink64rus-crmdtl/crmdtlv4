<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Оркестратор генерации ОДНОГО документа — с карточки записи или из
 * раздела «Документы» (App\Http\Controllers\Tenant\DocumentController).
 * Синхронно, не через очередь: рендер одного документа — быстрая операция,
 * не сопоставимая с bulk-экспортом из ExportEntitiesJob (CLAUDE.md, п.6 про
 * Horizon — там речь про массовые операции). Пользователь сразу получает PDF.
 */
class DocumentGenerationService
{
    public static function generate(DocumentTemplate $template, string $entityType, Model $entity): Document
    {
        $data = DocumentPlaceholderService::buildFor($entityType, $entity);
        $legalEntity = $data['legal_entity'];

        if (!$legalEntity) {
            throw new RuntimeException('Не удалось определить юрлицо для этой записи — у филиала не указано юрлицо.');
        }

        // Номер/дата — до рендера, а не после: собственный номер документа
        // ("Счёт №{{document.number}} от {{document.date}}") — стандартный
        // элемент печатной формы, должен быть доступен как обычный плейсхолдер.
        $number = DocumentNumerator::next($template, $legalEntity->id);
        $flat = array_merge($data['flat'], [
            'document.number' => $number,
            'document.date' => now()->translatedFormat('d F Y'),
        ]);

        $html = (new DocumentRenderer())->render($template->body, $flat, $data['tables']);

        $pdf = Pdf::loadHTML($html);
        $pdfContent = $pdf->output();

        $document = Document::create([
            'branch_id' => $entity->branch_id,
            'document_template_id' => $template->id,
            'documentable_type' => get_class($entity),
            'documentable_id' => $entity->id,
            'number' => $number,
            'title' => $template->name . ' №' . $number,
            'created_by' => auth()->id(),
        ]);

        // Номер может содержать "/" (год, "АКТ-000001/2026") — для файловой
        // системы это разделитель пути, а не часть имени.
        $safeFileName = str_replace('/', '-', $number) . '.pdf';

        $document->addMediaFromString($pdfContent)
            ->usingFileName($safeFileName)
            ->toMediaCollection('file');

        return $document;
    }
}
