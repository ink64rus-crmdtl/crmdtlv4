<?php

use Illuminate\Support\Facades\Route;

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        
        Route::get('/', function () {
            return '<div style="font-family: sans-serif; text-align: center; margin-top: 50px;">
                        <h1>Лендинг SaaS-платформы Детейлинг CRM</h1>
                        <p>Система успешно работает на Linux (Ubuntu 24.04)</p>
                    </div>';
        });

    });
}