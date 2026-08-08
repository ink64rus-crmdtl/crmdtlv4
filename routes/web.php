<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Central\RegisterTenantController;
use App\Http\Controllers\Central\Admin\AuthController as PlatformAdminAuthController;
use App\Http\Controllers\Central\Admin\DashboardController as PlatformAdminDashboardController;
use App\Http\Controllers\Central\Admin\TenantController as PlatformAdminTenantController;
use App\Http\Controllers\Central\Admin\PlatformSettingController;

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {

        Route::get('/', function () {
            return '<div style="font-family: sans-serif; text-align: center; margin-top: 50px;">
                        <h1>Лендинг SaaS-платформы Детейлинг CRM</h1>
                        <p>Система успешно работает на Linux (Ubuntu 24.04)</p>
                        <p><a href="/register" style="display: inline-block; padding: 12px 24px; background: #102a43; color: white; border-radius: 6px; text-decoration: none; font-weight: bold; margin-top: 15px;">Зарегистрировать детейлинг-центр</a></p>
                    </div>';
        });

        Route::get('/register', [RegisterTenantController::class, 'create'])->name('central.register.create');
        Route::post('/register', [RegisterTenantController::class, 'store'])->name('central.register.store');

        // Кабинет администратора платформы (Фаза 16) — отдельный гвард
        // 'platform_admin', см. config/auth.php и App\Models\Central\PlatformAdmin.
        // Нет публичной регистрации — только artisan platform:create-admin.
        Route::middleware('guest:platform_admin')->group(function () {
            Route::get('/admin/login', [PlatformAdminAuthController::class, 'create'])->name('central.admin.login');
            Route::post('/admin/login', [PlatformAdminAuthController::class, 'store'])->name('central.admin.login.store');
        });

        Route::middleware('auth:platform_admin')->prefix('admin')->name('central.admin.')->group(function () {
            Route::post('/logout', [PlatformAdminAuthController::class, 'destroy'])->name('logout');
            Route::get('/dashboard', [PlatformAdminDashboardController::class, 'index'])->name('dashboard');
            Route::get('/tenants', [PlatformAdminTenantController::class, 'index'])->name('tenants.index');
            Route::get('/settings', [PlatformSettingController::class, 'index'])->name('settings.index');
            Route::post('/settings', [PlatformSettingController::class, 'update'])->name('settings.update');
        });

    });
}