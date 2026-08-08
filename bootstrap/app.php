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
    // Намеренно БЕЗ channels: __DIR__.'/../routes/channels.php' здесь: withRouting(channels: ...)
    // регистрирует POST /broadcasting/auth глобально под 'web' — БЕЗ InitializeTenancyByDomain,
    // значит Broadcast::channel() коллбэки при авторизации канала стучались бы не в ту БД
    // (центральную/landlord, где нет chats/messages). Вместо этого Broadcast::routes() +
    // routes/channels.php подключены внутри routes/tenant.php, в той же tenancy+auth группе,
    // что и остальные бизнес-маршруты — см. там.
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Вебхуки от Wappi/SMS Aero и т.п. — внешние сервисы, у них нет и не может
        // быть нашего CSRF-токена. Защита от подделки — сам webhook_token в URL
        // (см. Channel.webhook_token), не CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
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

        // Напоминания о записи (Фаза 11.1) — чаще, чем прочие джобы: окно
        // "за N часов до визита" узкое, часовая точность тут заметна клиенту.
        // Часовой пояс не нужен — сравнение "сейчас < start_at <= сейчас+N" в UTC.
        $schedule->command('tenants:run appointments:send-reminders')
            ->everyFifteenMinutes()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
