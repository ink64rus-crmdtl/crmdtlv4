<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // tenants:run сам оборачивает выполнение в tenancy()->initialize() для каждого тенанта.
        // Ежечасно, не dailyAt('00:00') — филиалы тенанта могут быть в разных часовых поясах
        // (Branch::timezone), сервер работает в UTC. Сама команда решает, для каких поясов
        // сейчас "только что наступила полночь" — см. TakeDailyAccountSnapshots.
        $schedule->command('tenants:run snapshots:accounts')
            ->hourly()
            ->withoutOverlapping();

        // Начисление оклада (Фаза 10.3) — тот же принцип: ежечасно, команда сама
        // решает, у какого часового пояса сейчас полночь И совпадает ли сегодняшнее
        // число с настроенным днём начисления. См. AccruePayrollSalaries.
        $schedule->command('tenants:run payroll:accrue-salaries')
            ->hourly()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
