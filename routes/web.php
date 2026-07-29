<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Central\RegisterTenantController;

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

    });
}