<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\PreventSandboxTenantHttpAccess;
use App\Http\Controllers\DumpController;
use App\Http\Controllers\Tenant\LegalEntityController;
use App\Http\Controllers\Tenant\AccountController;
use Illuminate\Foundation\Application;
use Inertia\Inertia;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventSandboxTenantHttpAccess::class,
    PreventAccessFromCentralDomains::class,
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
    });

    require __DIR__.'/auth.php';
});