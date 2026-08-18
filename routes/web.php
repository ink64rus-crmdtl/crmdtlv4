<?php

use App\Http\Controllers\Central\Admin\AuthController as PlatformAdminAuthController;
use App\Http\Controllers\Central\Admin\DashboardController as PlatformAdminDashboardController;
use App\Http\Controllers\Central\Admin\PlatformDocumentTemplateController;
use App\Http\Controllers\Central\Admin\PlatformSettingController;
use App\Http\Controllers\Central\Admin\TenantController as PlatformAdminTenantController;
use App\Http\Controllers\Central\RegisterTenantController;
use Illuminate\Support\Facades\Route;

// НЕ регистрируем эти маршруты в цикле по config('tenancy.central_domains')
// через Route::domain($domain) — раньше так и было (по одной регистрации на
// каждый домен), но это заводит ДУБЛИКАТЫ одних и тех же имён маршрутов.
// Laravel в таблице имён маршрутов (RouteCollection::addLookups()) оставляет
// ПЕРВУЮ регистрацию с данным именем, а не последнюю — поэтому route()
// (и Ziggy на фронте) всегда резолвил central.admin.login.store и другие
// central.*-маршруты в домен, стоящий в central_domains ПЕРВЫМ (127.0.0.1),
// даже если пользователь открыл страницу на localhost. Сабмит формы (POST)
// в этом случае уходил на другой origin — кросс-доменно, без нужных cookie/
// CSRF, браузер тихо ронял запрос: кнопка "Войти" визуально ничего не делала
// и без единой ошибки на экране. Сама GET-страница при этом открывалась
// нормально (HTTP-роутинг matching по методу+URI+домену дублирования не
// боится, ломается только резолвинг ИМЕНИ маршрута на фронте).
//
// Матчинг именно по central_domains не критичен для корректности: эти
// маршруты не пересекаются по URI ни с одним маршрутом routes/tenant.php
// (там нет ни /, ни /register, ни /admin/*), так что регистрация без
// Route::domain() ничего не открывает лишнего — просто не дублирует имена.
Route::get('/', function () {
    return '<div style="font-family: sans-serif; text-align: center; margin-top: 50px;">
                <h1>Лендинг SaaS-платформы Детейлинг CRM</h1>
                <p>Система успешно работает на Linux (Ubuntu 24.04)</p>
                <p><a href="/register-company" style="display: inline-block; padding: 12px 24px; background: #102a43; color: white; border-radius: 6px; text-decoration: none; font-weight: bold; margin-top: 15px;">Зарегистрировать детейлинг-центр</a></p>
            </div>';
});

// Путь ИМЕННО /register-company, не /register: routes/tenant.php подключает
// стандартный Laravel Breeze routes/auth.php (Route::get('register', ...)
// ->name('register')) внутри своей tenancy-группы — БЕЗ Route::domain(),
// только через middleware (InitializeTenancyByDomain и т.п., проверка
// домена — только в РАНТАЙМЕ, на этапе обработки запроса). На этапе
// РЕГИСТРАЦИИ маршрутов (до всякого запроса) оба GET/POST /register —
// центральный (этот) и тенантский (Breeze) — оказываются одним и тем же
// URI+методом без домена в Illuminate\Routing\RouteCollection, и второй
// (регистрируется позже — routes/tenant.php грузится из TenancyServiceProvider
// через $this->app->booted(...), то есть ПОЗЖЕ routes/web.php) молча
// вытесняет central.register.create/store из таблицы маршрутов — GET
// /register на центральном домене отдавал 404 несмотря на то, что маршрут
// был явно объявлен здесь. Другое имя URI устраняет коллизию полностью.
Route::get('/register-company', [RegisterTenantController::class, 'create'])->name('central.register.create');
Route::post('/register-company', [RegisterTenantController::class, 'store'])->name('central.register.store');
// Статус провижининга (создание БД/миграции/сидеры ушли в очередь) —
// форма регистрации поллит его и сама переходит на login нового тенанта.
Route::get('/register-company/status/{tenant}', [RegisterTenantController::class, 'status'])->name('central.register.status');

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
    Route::get('/document-templates', [PlatformDocumentTemplateController::class, 'index'])->name('document-templates.index');
    Route::post('/document-templates', [PlatformDocumentTemplateController::class, 'store'])->name('document-templates.store');
    Route::put('/document-templates/{platformDocumentTemplate}', [PlatformDocumentTemplateController::class, 'update'])->name('document-templates.update');
    Route::delete('/document-templates/{platformDocumentTemplate}', [PlatformDocumentTemplateController::class, 'destroy'])->name('document-templates.destroy');
    Route::get('/document-templates/{platformDocumentTemplate}/preview', [PlatformDocumentTemplateController::class, 'preview'])->name('document-templates.preview');
});
