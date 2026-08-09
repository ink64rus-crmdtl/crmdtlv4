<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;

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
        [$legalEntity, $html, $number] = self::renderFor($template, $entityType, $entity, null);

        $document = Document::create([
            'branch_id' => $entity->branch_id,
            'document_template_id' => $template->id,
            'documentable_type' => get_class($entity),
            'documentable_id' => $entity->id,
            'number' => $number,
            'title' => $template->name . ' №' . $number,
            'created_by' => auth()->id(),
        ]);

        self::attachPdf($document, $html, $number);

        return $document;
    }

    /**
     * "Заменить документ" (App\Http\Controllers\Tenant\DocumentController::
     * replace()) — В ОТЛИЧИЕ от generate(), номер НЕ выдаётся заново через
     * DocumentNumerator: это тот же самый, уже выпущенный документ, просто с
     * актуальным содержимым — по явному запросу пользователя, который хочет
     * исправить/обновить конкретный документ, не заводя новый номер (если
     * важна полная история версий — см. regenerateAsNew() в контроллере,
     * там номер новый, а этот документ просто помечается заменённым).
     * created_at переставляется на "сейчас" — это и есть момент, когда
     * текущее содержимое документа стало актуальным (Document::isStale()
     * сравнивает именно с ним), а не техническая метка первого создания.
     */
    public static function replace(Document $document, DocumentTemplate $template, string $entityType, Model $entity): Document
    {
        [, $html] = self::renderFor($template, $entityType, $entity, $document->number);

        $document->forceFill([
            'branch_id' => $entity->branch_id,
            'title' => $template->name . ' №' . $document->number,
            'created_at' => now(),
            'superseded_by_document_id' => null,
        ])->save();

        self::attachPdf($document, $html, $document->number);

        return $document->fresh();
    }

    /**
     * @return array{0: ?\App\Models\LegalEntity, 1: string, 2: string} [юрлицо, html, номер]
     */
    private static function renderFor(DocumentTemplate $template, string $entityType, Model $entity, ?string $existingNumber): array
    {
        $data = DocumentPlaceholderService::buildFor($entityType, $entity);
        $legalEntity = $data['legal_entity'];

        // Точка может работать вообще без юрлица (осознанно допустимо —
        // CLAUDE.md), и заказ мог не выбрать явно ни одного из нескольких —
        // документ всё равно формируется, просто с пустыми реквизитами
        // (DocumentPlaceholderService уже отдаёт flat без legal_entity.* в
        // этом случае, а DocumentRenderer вырезает неподставленные {{ключ}}).
        // Атомарный сквозной нумератор (DocumentNumerator) скопирован ПО
        // ЮРЛИЦУ — без него не к чему привязать сквозную нумерацию, поэтому
        // здесь отдельная, не претендующая на строгую бухгалтерскую
        // последовательность схема "БН-<дата>-<рандом>" — документ без
        // юрлица и не является формальным финансовым документом с
        // требованием сплошной нумерации.
        $number = $existingNumber ?? ($legalEntity
            ? DocumentNumerator::next($template, $legalEntity->id)
            : 'БН-' . now()->format('ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)));

        // Номер/дата — до рендера, а не после: собственный номер документа
        // ("Счёт №{{document.number}} от {{document.date}}") — стандартный
        // элемент печатной формы, должен быть доступен как обычный плейсхолдер.
        $flat = array_merge($data['flat'], [
            'document.number' => $number,
            'document.date' => now()->translatedFormat('d F Y'),
        ]);

        $html = (new DocumentRenderer())->render($template->body, $flat, $data['tables']);

        return [$legalEntity, $html, $number];
    }

    private static function attachPdf(Document $document, string $html, string $number): void
    {
        $pdf = Pdf::loadHTML($html);
        $pdfContent = $pdf->output();

        // Номер может содержать "/" (год, "АКТ-000001/2026") — для файловой
        // системы это разделитель пути, а не часть имени.
        $safeFileName = str_replace('/', '-', $number) . '.pdf';

        // Коллекция 'file' — singleFile() (Document::registerMediaCollections):
        // при replace() новый файл сам заменяет старый, отдельный clearMediaCollection() не нужен.
        $document->addMediaFromString($pdfContent)
            ->usingFileName($safeFileName)
            ->toMediaCollection('file');
    }
}
