<?php

namespace App\Services;

use App\Models\Branch;

/**
 * Сервер работает в UTC (config('app.timezone')), но бизнес-день клиента определяется
 * часовым поясом точки (Branch::timezone) или, если он не задан, поясом тенанта
 * (Tenant::timezone — обязателен при регистрации). Любая логика, зависящая от того,
 * "какой сейчас день" у клиента (дефолтные даты операций, снэпшоты, закрытие периода),
 * обязана идти через этот сервис, а не через голый now()/Carbon::today() (= UTC).
 *
 * Метки аудита (created_at, edited_at, reconciled_at и т.п.) — исключение, они остаются
 * в UTC/абсолютном времени; в локальное время зрителя их переводит браузer.
 */
class TimezoneResolver
{
    public static function forBranch(?int $branchId): string
    {
        if ($branchId) {
            $timezone = Branch::find($branchId)?->timezone;
            if ($timezone) {
                return $timezone;
            }
        }

        return self::forTenant();
    }

    public static function forTenant(): string
    {
        return tenant('timezone') ?: config('app.timezone');
    }
}
