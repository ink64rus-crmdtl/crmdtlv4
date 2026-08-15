<?php

namespace App\Services;

use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Кастомное поле (EAV, CLAUDE.md §5) с CustomFieldDefinition.use_in_templates=true
 * становится доступно как плейсхолдер {{<entity_type>.custom.<key>}} — и в
 * шаблонах документов (App\Services\Documents\DocumentPlaceholderService), и
 * в шаблонах сообщений (App\Services\Messaging\MessageTemplateService). Один
 * сервис на обоих потребителей, чтобы формат значения (дата/чекбокс/список)
 * не разъезжался между печатной формой и текстом сообщения.
 */
class CustomFieldPlaceholderService
{
    /**
     * Значения полей конкретной записи — то, что реально подставится в
     * готовый документ/сообщение.
     *
     * @return array<string,string>
     */
    public static function forEntity(string $entityType, ?Model $entity): array
    {
        if (! $entity) {
            return [];
        }

        $defs = CustomFieldDefinition::where('entity_type', $entityType)
            ->where('use_in_templates', true)
            ->get();

        if ($defs->isEmpty()) {
            return [];
        }

        $values = CustomFieldValue::where('entity_type', $entityType)
            ->where('entity_id', $entity->id)
            ->whereIn('custom_field_definition_id', $defs->pluck('id'))
            ->get()
            ->keyBy('custom_field_definition_id');

        $placeholders = [];
        foreach ($defs as $def) {
            $placeholders["{$entityType}.custom.{$def->key}"] = self::formatValue($def, $values->get($def->id));
        }

        return $placeholders;
    }

    /**
     * Подсказки для конструктора шаблонов (ключ => человекочитаемая метка,
     * без значения — только определения) — тот же реестр, что показывает
     * пикер плейсхолдеров рядом со стандартными полями сущности.
     *
     * @return array<string,string>
     */
    public static function hintsFor(string $entityType): array
    {
        return CustomFieldDefinition::where('entity_type', $entityType)
            ->where('use_in_templates', true)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (CustomFieldDefinition $def) => [
                "{$entityType}.custom.{$def->key}" => 'Кастомное поле: '.self::label($def),
            ])
            ->all();
    }

    private static function formatValue(CustomFieldDefinition $def, ?CustomFieldValue $value): string
    {
        if (! $value) {
            return '';
        }

        return match ($def->type) {
            'date' => $value->value_date?->format('d.m.Y') ?? '',
            'checkbox' => $value->value_text === '1' ? 'Да' : 'Нет',
            'number' => $value->value_number !== null ? rtrim(rtrim((string) $value->value_number, '0'), '.') : '',
            default => $value->value_text ?? '',
        };
    }

    private static function label(CustomFieldDefinition $def): string
    {
        $label = $def->label;

        if (! is_array($label)) {
            return (string) $label;
        }

        return $label[app()->getLocale()] ?? (current($label) ?: $def->key);
    }
}
