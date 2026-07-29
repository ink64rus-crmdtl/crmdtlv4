<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\PreventSandboxTenantHttpAccess;
use App\Http\Middleware\SetBranchContext;
use App\Http\Controllers\DumpController;
use App\Http\Controllers\Tenant\LegalEntityController;
use App\Http\Controllers\Tenant\AccountController;
use App\Http\Controllers\Tenant\BranchController;
use App\Http\Controllers\Tenant\SystemController;
use App\Http\Controllers\Tenant\BusinessDirectionController;
use App\Http\Controllers\Tenant\WarehouseSettingsController;
use Illuminate\Foundation\Application;
use Inertia\Inertia;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventSandboxTenantHttpAccess::class,
    PreventAccessFromCentralDomains::class,
    SetBranchContext::class,
])->group(function () {

    Route::get('/', function () {
        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    });

    Route::middleware('auth')->group(function () {
        Route::get('/dashboard', function () {
            return Inertia::render('Dashboard');
        })->name('dashboard');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        
        // Системный раздел (Логи и Дамп)
        Route::get('/system', [SystemController::class, 'index'])->name('system.index');
        Route::get('/dump', [DumpController::class, 'download'])->name('dump');

        // Главный маршрут настроек (редирект на Юрлица)
        Route::get('/settings', function () {
            return redirect()->route('settings.legal-entities.index');
        })->name('settings');

        // Настройки: Юридические лица
        Route::get('/settings/legal-entities', [LegalEntityController::class, 'index'])->name('settings.legal-entities.index');
        Route::post('/settings/legal-entities', [LegalEntityController::class, 'store'])->name('settings.legal-entities.store');
        Route::put('/settings/legal-entities/{legalEntity}', [LegalEntityController::class, 'update'])->name('settings.legal-entities.update');
        Route::delete('/settings/legal-entities/{legalEntity}', [LegalEntityController::class, 'destroy'])->name('settings.legal-entities.destroy');

        // Настройки: Счета
        Route::post('/settings/accounts', [AccountController::class, 'store'])->name('settings.accounts.store');
        Route::put('/settings/accounts/{account}', [AccountController::class, 'update'])->name('settings.accounts.update');
        Route::delete('/settings/accounts/{account}', [AccountController::class, 'destroy'])->name('settings.accounts.destroy');

        // Настройки: Филиалы
        Route::get('/settings/branches', [BranchController::class, 'index'])->name('settings.branches.index');
        Route::post('/settings/branches', [BranchController::class, 'store'])->name('settings.branches.store');
        Route::put('/settings/branches/{branch}', [BranchController::class, 'update'])->name('settings.branches.update');
        Route::delete('/settings/branches/{branch}', [BranchController::class, 'destroy'])->name('settings.branches.destroy');

        // Переключение филиала
        Route::post('/branches/{branch}/switch', [BranchController::class, 'switch'])->name('branches.switch');

        // Настройки: Направления бизнеса
        Route::get('/settings/business-directions', [BusinessDirectionController::class, 'index'])->name('settings.business-directions.index');
        Route::post('/settings/business-directions', [BusinessDirectionController::class, 'store'])->name('settings.business-directions.store');
        Route::put('/settings/business-directions/{businessDirection}', [BusinessDirectionController::class, 'update'])->name('settings.business-directions.update');
        Route::delete('/settings/business-directions/{businessDirection}', [BusinessDirectionController::class, 'destroy'])->name('settings.business-directions.destroy');

        // Настройки: Склад
        Route::get('/settings/warehouse', [WarehouseSettingsController::class, 'index'])->name('settings.warehouse.index');
        Route::post('/settings/warehouse', [WarehouseSettingsController::class, 'store'])->name('settings.warehouse.store');
    });

    require __DIR__.'/auth.php';
});