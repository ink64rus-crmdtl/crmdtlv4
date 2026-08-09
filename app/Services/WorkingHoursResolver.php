<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Setting;

/**
 * Часы работы точки (Branch::working_hours) — необязательное поле; если для
 * конкретной точки своё расписание не задано, действует расписание по умолчанию
 * всего детейлинг-центра (settings.default_working_hours). Тот же принцип
 * "точка -> фолбэк на тенант", что и у TimezoneResolver.
 *
 * Формат расписания: массив из 7 элементов {day, is_open, open, close}.
 */
class WorkingHoursResolver
{
    public static function forBranch(?int $branchId): ?array
    {
        if ($branchId) {
            $hours = Branch::find($branchId)?->working_hours;
            if (!empty($hours)) {
                return $hours;
            }
        }

        return self::forTenant();
    }

    public static function forTenant(): ?array
    {
        $value = Setting::where('key', 'default_working_hours')->value('value');

        return $value ? json_decode($value, true) : null;
    }
}
