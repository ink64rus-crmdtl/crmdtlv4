<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class QueryFilterService
{
    /**
     * Применяет параметры поиска, фильтрации и сортировки к запросу.
     *
     * @param Builder $query Eloquent Builder
     * @param array $params Массив параметров (обычно $request->all())
     * @param array $searchableColumns Колонки для глобального поиска (LIKE %search%)
     * @param string|null $entityType Тип сущности для EAV-фильтрации (например, 'client')
     * @return Builder
     */
    public static function apply(Builder $query, array $params, array $searchableColumns = [], ?string $entityType = null): Builder
    {
        // 1. Глобальный поиск
        if (!empty($params['search']) && !empty($searchableColumns)) {
            $searchTerm = '%' . $params['search'] . '%';
            
            $query->where(function ($q) use ($searchableColumns, $searchTerm) {
                foreach ($searchableColumns as $index => $column) {
                    if ($index === 0) {
                        $q->where($column, 'LIKE', $searchTerm);
                    } else {
                        $q->orWhere($column, 'LIKE', $searchTerm);
                    }
                }
            });
        }

        // 2. Точечные фильтры
        if (!empty($params['filters']) && is_array($params['filters'])) {
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
                            ->whereColumn('custom_field_values.entity_id', $tableName . '.id')
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

        // 3. Сортировка
        if (!empty($params['sort_by'])) {
            $direction = (!empty($params['sort_dir']) && strtolower($params['sort_dir']) === 'desc') ? 'desc' : 'asc';
            $query->orderBy($params['sort_by'], $direction);
        }

        return $query;
    }
}