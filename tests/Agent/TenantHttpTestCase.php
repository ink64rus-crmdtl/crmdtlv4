<?php

namespace Tests\Agent;

use App\Http\Middleware\PreventSandboxTenantHttpAccess;

/**
 * Базовый класс для тестов, которым нужен НАСТОЯЩИЙ HTTP-проход через
 * маршрутизацию тенанта (роуты авторизации из routes/auth.php — /login,
 * /register, /profile и т.п.), а не прямой вызов Eloquent/сервисов в обход
 * HTTP-стека, как у остальных тестов на TenantAgentTestCase.
 *
 * ⚠️ ЕДИНСТВЕННОЕ отличие от TenantAgentTestCase — снимает
 * PreventSandboxTenantHttpAccess ТОЛЬКО для запросов внутри ЭТОГО
 * PHPUnit-процесса (withoutMiddleware работает через контейнер текущего
 * теста, реального сетевого пути наружу не открывает и саму middleware
 * не меняет — она полноценно защищает все настоящие HTTP-запросы, включая
 * ручной curl -H "Host: agent-sandbox.localhost"). Это НЕ ослабление
 * изоляции: middleware существует, чтобы sandbox-тенант не был доступен
 * ИЗ БРАУЗЕРА/интернета — доклбок самой PreventSandboxTenantHttpAccess
 * прямо перечисляет тесты через phpunit.tenant.xml как штатный, ожидаемый
 * потребитель наравне с CLI. До этого класса тесты просто не пользовались
 * этим разрешением, потому что не делали HTTP-запросов вообще.
 *
 * Используй tenantUrl() из TenantAgentTestCase для абсолютного URL —
 * относительные $this->get('/login') уйдут на central-домен и 404'нут
 * ещё до этой middleware (её там даже нет, см. routes/web.php).
 */
abstract class TenantHttpTestCase extends TenantAgentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventSandboxTenantHttpAccess::class);
    }
}
