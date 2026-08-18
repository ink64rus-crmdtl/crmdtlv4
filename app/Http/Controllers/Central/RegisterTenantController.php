<?php

namespace App\Http\Controllers\Central;

use DateTimeImmutable;
use DateTimeZone;
use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenantJob;
use App\Models\Tenant;
use App\Services\CountryConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisterTenantController extends Controller
{
    /**
     * Список стран для формы регистрации — ЕДИНЫЙ источник с
     * CountryConfigService (схема реквизитов юрлица), а не отдельный
     * хардкод. Раньше форма предлагала DE/RU/KZ/US, а реквизиты/НДС были
     * готовы только для RU/BY/KZ/GE — списки расходились, и тенант мог
     * зарегистрироваться под страной, для которой Settings/LegalEntities
     * показал бы схему по фолбэку (RU) вместо реальной. Добавление новой
     * страны теперь = одна запись в CountryConfigService::getSupportedCountries(),
     * без правок этого контроллера и Register.vue.
     */
    public function create(): Response
    {
        return Inertia::render('Central/Register', [
            'countries' => CountryConfigService::getSupportedCountries(),
            'timezones' => $this->russianTimezones(),
        ]);
    }

    /**
     * Российские часовые пояса для формы регистрации: города (IANA) + смещение
     * от UTC, например «Europe/Saratov (+4)». Берем из PHP по стране RU —
     * это авторитетный список, не хардкод. Смещение считается для текущего
     * момента (в России DST нет — значение стабильно).
     */
    private function russianTimezones(): array
    {
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'RU');
        sort($zones);

        $result = [];
        foreach ($zones as $zone) {
            $offsetHours = (new DateTimeZone($zone))->getOffset(new DateTimeImmutable('now', new DateTimeZone($zone))) / 3600;
            $result[$zone] = sprintf('%s (+%d)', $zone, $offsetHours);
        }

        return $result;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string', 'alpha_num', 'lowercase', 'max:50'],
            'country_code' => ['required', 'string', 'size:2', Rule::in(array_keys(CountryConfigService::getSupportedCountries()))],
            'base_currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'default_locale' => ['required', 'string', 'max:5'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $domainName = $validated['subdomain'].'.localhost';

        // Проверяем занятость поддомена
        if (DB::table('domains')->where('domain', $domainName)->exists()) {
            return back()->withErrors(['subdomain' => 'Этот поддомен уже занят. Выберите другой.']);
        }

        try {
            // 1. Создаем тенанта в Центральной БД. Создание физической БД и
            // миграции уходят в очередь (TenancyServiceProvider,
            // shouldBeQueued(true)) — запрос не висит минуты.
            $tenant = Tenant::create([
                'id' => $validated['subdomain'],
                'country_code' => strtoupper($validated['country_code']),
                'base_currency' => strtoupper($validated['base_currency']),
                'timezone' => $validated['timezone'],
                'default_locale' => strtolower($validated['default_locale']),
            ]);

            // 2. Привязываем домен
            $tenant->domains()->create([
                'domain' => $domainName,
            ]);

            // 3. Наполнение (сидеры + администратор) — отдельной джобой следом
            // за миграциями в той же очереди. Фронт следит за готовностью через
            // status()-эндпоинт и сам перейдёт на login нового тенанта.
            ProvisionTenantJob::dispatch($tenant, $validated);

            $redirectUrl = 'http://'.$domainName.':8000/login';

            return Inertia::render('Central/Register', [
                'countries' => CountryConfigService::getSupportedCountries(),
                'timezones' => $this->russianTimezones(),
                'registration' => [
                    'tenant_id' => $tenant->id,
                    'redirect_url' => $redirectUrl,
                ],
            ]);

        } catch (\Throwable $e) {
            \Log::error('Tenant Registration Failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return back()->withErrors([
                'company_name' => 'Ошибка при создании CRM: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Статус провижининга тенанта для формы регистрации: pending → ready/failed.
     * Пайплайн и ProvisionTenantJob пишут результат в data.provisioned_at /
     * data.provision_error, поэтому готовность не гадаем по таблицам.
     */
    public function status(Tenant $tenant)
    {
        // provisioned_at / provision_error — виртуальные атрибуты тенанта
        // (stancl VirtualColumn раскладывает JSON-колонку data в отдельные
        // атрибуты при retrieved, сама $tenant->data при этом null).
        if ($tenant->provision_error !== null) {
            return response()->json(['status' => 'failed']);
        }

        if ($tenant->provisioned_at !== null) {
            return response()->json(['status' => 'ready']);
        }

        return response()->json(['status' => 'pending']);
    }
}
