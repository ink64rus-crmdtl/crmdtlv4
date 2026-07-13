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

        if ($tenant->timezone) {
            Config::set('app.timezone', $tenant->timezone);
            date_default_timezone_set($tenant->timezone);
        }

        if ($tenant->base_currency) {
            Config::set('tenant.base_currency', $tenant->base_currency);
        }

        if ($tenant->country_code) {
            Config::set('tenant.country_code', $tenant->country_code);
        }
    }
}