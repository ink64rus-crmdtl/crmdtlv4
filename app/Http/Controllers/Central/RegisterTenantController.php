<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CountryConfigService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string', 'alpha_num', 'lowercase', 'max:50'],
            'country_code' => ['required', 'string', 'size:2', Rule::in(array_keys(CountryConfigService::getSupportedCountries()))],
            'base_currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', 'max:100'],
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
            // 1. Создаем тенанта в Центральной БД
            // Пакет stancl/tenancy автоматически создаст физическую базу и накатит миграции
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

            // 3. Наполняем базу нового клиента
            $tenant->run(function () use ($validated) {
                // Запускаем сидеры ролей и модулей
                (new TenantRoleSeeder)->run();
                (new ModuleSeeder)->run();
                // Стартовая воронка продаж со стадиями (Фаза 17) — без неё
                // раздел «Продажи» открылся бы у нового тенанта пустым, и
                // первую сделку было бы некуда положить.
                (new PipelineSeeder)->run();

                // Создаем администратора
                $owner = User::create([
                    'name' => $validated['admin_name'],
                    'email' => $validated['admin_email'],
                    'password' => Hash::make($validated['password']),
                ]);

                // Выдаем роль admin
                $owner->assignRole('admin');
            });

            // Формируем редирект на личную CRM клиента
            $redirectUrl = 'http://'.$domainName.':8000/login';

            return Inertia::location($redirectUrl);

        } catch (\Throwable $e) {
            \Log::error('Tenant Registration Failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return back()->withErrors([
                'company_name' => 'Ошибка при создании CRM: '.$e->getMessage(),
            ]);
        }
    }
}
