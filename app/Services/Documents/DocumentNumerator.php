<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentNumerator as DocumentNumeratorModel;
use App\Models\DocumentTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Атомарная выдача следующего номера документа — НЕ COUNT(*)+1 по documents
 * (см. миграцию create_document_numerators_table за обоснование: пропуски
 * номеров при удалении в общем случае норма и у Bitrix24, и у 1С, а
 * COUNT-подход даёт дублирующиеся номера при удалении/параллельной
 * генерации). Тем не менее reclaimIfLast() ниже — по явному запросу —
 * откатывает последний номер назад при удалении САМОГО СВЕЖЕГО документа
 * связки, чтобы типичный кейс "сформировал по ошибке — сразу удалил" не
 * оставлял дыру в нумерации; более старый документ, удалённый не по
 * порядку, дыру всё равно оставит (иначе разъехались бы номера того, что
 * выдано после него).
 */
class DocumentNumerator
{
    public static function next(DocumentTemplate $template, int $legalEntityId): string
    {
        $year = $template->number_reset_yearly ? (int) now()->format('Y') : 0;

        $number = DB::transaction(function () use ($template, $legalEntityId, $year) {
            $numerator = DocumentNumeratorModel::where([
                'legal_entity_id' => $legalEntityId,
                'document_template_id' => $template->id,
                'year' => $year,
            ])->lockForUpdate()->first();

            if (!$numerator) {
                // Гонка: если между проверкой выше и созданием другая
                // параллельная транзакция уже вставила строку — ловим
                // нарушение уникального индекса и просто перечитываем её
                // с блокировкой, вместо падения с ошибкой у пользователя.
                try {
                    $numerator = DocumentNumeratorModel::create([
                        'legal_entity_id' => $legalEntityId,
                        'document_template_id' => $template->id,
                        'year' => $year,
                        'last_number' => 0,
                    ]);
                } catch (QueryException $e) {
                    $numerator = DocumentNumeratorModel::where([
                        'legal_entity_id' => $legalEntityId,
                        'document_template_id' => $template->id,
                        'year' => $year,
                    ])->lockForUpdate()->firstOrFail();
                }
            }

            $numerator->increment('last_number');

            return $numerator->last_number;
        });

        return self::format($template, $number, $year);
    }

    /**
     * Если удаляемый документ — последний выданный номер своей связки
     * (юрлицо, шаблон, год), откатывает нумератор на 1 назад, чтобы
     * следующий сгенерированный документ получил тот же номер. Иначе —
     * не трогает нумератор (дыра неизбежна и корректна, см. класс-докблок).
     */
    public static function reclaimIfLast(Document $document): void
    {
        $template = $document->template;
        $legalEntityId = $document->branch?->legal_entity_id;

        if (!$template || !$legalEntityId) {
            return;
        }

        DB::transaction(function () use ($document, $template, $legalEntityId) {
            $year = $template->number_reset_yearly ? (int) $document->created_at->format('Y') : 0;

            $numerator = DocumentNumeratorModel::where([
                'legal_entity_id' => $legalEntityId,
                'document_template_id' => $template->id,
                'year' => $year,
            ])->lockForUpdate()->first();

            if (!$numerator || $numerator->last_number < 1) {
                return;
            }

            if (self::format($template, $numerator->last_number, $year) === $document->number) {
                $numerator->decrement('last_number');
            }
        });
    }

    private static function format(DocumentTemplate $template, int $number, int $year): string
    {
        $prefix = $template->number_prefix ?? '';
        $formatted = $prefix . str_pad((string) $number, 6, '0', STR_PAD_LEFT);

        return $year > 0 ? $formatted . '/' . $year : $formatted;
    }
}
