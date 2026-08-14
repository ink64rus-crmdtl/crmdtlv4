<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class QueryFilterService
{
    /**
     * Применяет параметры поиска, фильтрации и сортировки к запросу.
     *
     * @param  Builder  $query  Eloquent Builder
     * @param  array  $params  Массив параметров (обычно $request->all())
     * @param  array  $searchableColumns  Колонки для глобального поиска (LIKE %search%). Помимо
     *                                    колонок текущей таблицы, поддерживает dot-нотацию для связей — например 'client.name'
     *                                    или 'vehicle.plate_number' — ищет через whereHas по указанной связи модели.
     * @param  string|null  $entityType  Тип сущности для EAV-фильтрации (например, 'client')
     * @param  array  $allowedSorts  Белый список колонок, по которым разрешена сортировка через
     *                               sort_by (иначе сырой параметр запроса ушёл бы прямо в orderBy() — неожиданные колонки
     *                               для сортировки, в т.ч. из связанных таблиц не для этого предназначенных). Пустой массив
     *                               (по умолчанию) = sort_by полностью игнорируется, а не "разрешено всё" — колонки нужно
     *                               явно перечислить в контроллере.
     */
    public static function apply(Builder $query, array $params, array $searchableColumns = [], ?string $entityType = null, array $allowedSorts = []): Builder
    {
        // 1. Глобальный поиск
        if (! empty($params['search']) && ! empty($searchableColumns)) {
            $searchTerm = '%'.$params['search'].'%';

            $query->where(function ($q) use ($searchableColumns, $searchTerm) {
                foreach ($searchableColumns as $index => $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $relationColumn] = explode('.', $column, 2);
                        $whereHasMethod = $index === 0 ? 'whereHas' : 'orWhereHas';

                        $q->{$whereHasMethod}($relation, function ($relationQuery) use ($relationColumn, $searchTerm) {
                            $relationQuery->where($relationColumn, 'LIKE', $searchTerm);
                        });
                    } elseif ($index === 0) {
                        $q->where($column, 'LIKE', $searchTerm);
                    } else {
                        $q->orWhere($column, 'LIKE', $searchTerm);
                    }
                }
            });
        }

        // 2. Точечные фильтры
        if (! empty($params['filters']) && is_array($params['filters'])) {
            foreach ($params['filters'] as $key => $value) {
                // Пропускаем пустые значения
                if ($value === null || $value === '') {
                    continue;
                }

                // EAV-фильтрация (Кастомные поля)
                if (str_starts_with($key, 'cf_') && $entityType) {
                    $cfKey = substr($key, 3);

                    $query->whereExists(function ($subquery) use ($cfKey, $value, $entityType, $query) {
                        $tableName = $query->getModel()->getTable();

                        $subquery->select(DB::raw(1))
                            ->from('custom_field_values')
                            ->join('custom_field_definitions', 'custom_field_definitions.id', '=', 'custom_field_values.custom_field_definition_id')
                            ->whereColumn('custom_field_values.entity_id', $tableName.'.id')
                            ->where('custom_field_values.entity_type', $entityType)
                            ->where('custom_field_definitions.key', $cfKey)
                            ->where(function ($q) use ($value) {
                                if (is_array($value)) {
                                    $q->whereIn('custom_field_values.value_text', $value)
                                        ->orWhereIn('custom_field_values.value_number', $value);
                                } else {
                                    $q->where('custom_field_values.value_text', 'LIKE', "%{$value}%")
                                        ->orWhere('custom_field_values.value_number', $value)
                                        ->orWhere('custom_field_values.value_date', $value);
                                }
                            });
                    });

                    continue;
                }

                // Стандартная фильтрация по колонкам таблицы
                if (is_array($value)) {
                    $query->whereIn($key, $value);
                } else {
                    $query->where($key, $value);
                }
            }
        }

        // 3. Сортировка — многоколоночная с приоритетом (порядок в массиве = порядок
        // ORDER BY, т.е. первая колонка главная). sort_by/sort_dir приходят с фронта
        // как массивы (даже для одной колонки — см. useServerSort.js), но принимаем
        // и одиночный скаляр (?sort_by=name&sort_dir=asc, вбитый руками в URL).
        // Только колонки из белого списка (см. докблок apply()) — остальные молча
        // пропускаются, а не отклоняют всю сортировку целиком.
        $sortBy = (array) ($params['sort_by'] ?? []);
        $sortDir = (array) ($params['sort_dir'] ?? []);
        foreach ($sortBy as $i => $column) {
            if (! is_string($column) || ! in_array($column, $allowedSorts, true)) {
                continue;
            }
            $direction = (isset($sortDir[$i]) && strtolower((string) $sortDir[$i]) === 'desc') ? 'desc' : 'asc';
            $query->orderBy($column, $direction);
        }

        return $query;
    }
}
