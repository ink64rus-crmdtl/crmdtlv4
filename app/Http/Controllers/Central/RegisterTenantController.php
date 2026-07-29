<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisterTenantController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Central/Register');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string', 'alpha_num', 'lowercase', 'max:50'],
            'country_code' => ['required', 'string', 'size:2'],
            'base_currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', 'max:100'],
            'default_locale' => ['required', 'string', 'max:5'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $domainName = $validated['subdomain'] . '.localhost';

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
                (new TenantRoleSeeder())->run();
                (new ModuleSeeder())->run();

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
            $redirectUrl = 'http://' . $domainName . ':8000/login';

            return Inertia::location($redirectUrl);

        } catch (\Throwable $e) {
            \Log::error('Tenant Registration Failed: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return back()->withErrors([
                'company_name' => 'Ошибка при создании CRM: ' . $e->getMessage()
            ]);
        }
    }
}