<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Stancl\Tenancy\Events\TenancyInitialized;
use App\Services\TenantConfigService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) {
            if (isset($event->tenancy->tenant)) {
                TenantConfigService::configure($event->tenancy->tenant);
            }
        });

        // Неявное предоставление всех прав роли admin
        Gate::before(function ($user, $ability) {
            return $user->hasRole('admin') ? true : null;
        });
    }
}