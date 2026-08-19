<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Блокирует ЛЮБОЙ HTTP-доступ к sandbox-тенанту агента (см. AGENTS.md §1.3 —
 * CrmDevAgent/LarAgent, а также §2, phpunit.tenant.xml), независимо от того,
 * как именно резолвился домен.
 *
 * Важно: резолвинг через *.localhost сам по себе НЕ является защитой —
 * Host-заголовок можно подделать вручную (curl -H "Host: ..."), если запрос
 * доходит до сервера напрямую по IP. Поэтому проверяем явно id тенанта,
 * а не полагаемся на то, что домен "никто не найдёт".
 *
 * Sandbox-тенант предназначен ТОЛЬКО для:
 *  - CLI-команд (php artisan tinker, artisan-команды)
 *  - тестов через phpunit.tenant.xml (там tenancy инициализируется в PHP
 *    напрямую через tenancy()->initialize(), минуя HTTP-роутинг вообще)
 * Через веб — не должен быть доступен никогда.
 */
class PreventSandboxTenantHttpAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized && tenant('id') === $this->sandboxTenantId()) {
            abort(404);
        }

        return $next($request);
    }

    private function sandboxTenantId(): string
    {
        return config('tenancy.agent_sandbox_tenant_id', 'tenant_agent_sandbox');
    }
}