<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class TenantConfigService
{
    public static function configure(Tenant $tenant): void
    {
        if ($tenant->default_locale) {
            App::setLocale($tenant->default_locale);
            Carbon::setLocale($tenant->default_locale);
        }

        // Часовой пояс тенанта НЕ применяется к config('app.timezone')/date_default_timezone_set():
        // сервер и вся БД всегда работают в UTC (см. TimezoneResolver). Смена глобального PHP-таймзона
        // здесь ранее ломала интерпретацию "наивных" datetime-колонок Eloquent — они читались/писались
        // в поясе тенанта вместо UTC, хотя реально в БД всегда лежит UTC. Использовать TimezoneResolver::
        // forTenant()/forBranch() явно там, где нужен именно календарный день клиента.

        if ($tenant->base_currency) {
            Config::set('tenant.base_currency', $tenant->base_currency);
        }

        if ($tenant->country_code) {
            Config::set('tenant.country_code', $tenant->country_code);
        }
    }
}