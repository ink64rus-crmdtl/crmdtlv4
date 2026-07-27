<?php

namespace Tests\Agent;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * Базовый класс для тестов, которые должны реально выполняться против
 * физической tenant-БД sandbox-тенанта (раздел 16.5 системной инструкции,
 * TestRunnerGuard). Запускается ТОЛЬКО через phpunit.tenant.xml:
 *
 *   php artisan test --configuration=phpunit.tenant.xml
 *
 * Обычный `php artisan test` (phpunit.xml) эти тесты не подхватывает,
 * т.к. testsuite "Agent" в основном конфиге не объявлен.
 *
 * DatabaseTransactions, а не RefreshDatabase: RefreshDatabase дропает и
 * заново мигрирует схему при каждом прогоне — на реальной персистентной
 * MySQL-БД (не sqlite :memory:) это медленно и избыточно. Транзакция с
 * откатом после каждого теста безопаснее и быстрее для этого сценария.
 */
abstract class TenantAgentTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $sandboxTenantId = env('AI_AGENT_SANDBOX_TENANT', 'tenant_agent_sandbox');

        $tenant = Tenant::find($sandboxTenantId);

        if (! $tenant) {
            throw new RuntimeException(
                "Sandbox-тенант '{$sandboxTenantId}' не найден в central БД. " .
                'Проверь, что он создан (см. Часть 5 инструкции по деплою LarAgent) ' .
                'и что тесты запущены через --configuration=phpunit.tenant.xml, ' .
                'а не через обычный phpunit.xml (там DB_DATABASE=crmdtlv4 не будет виден).'
            );
        }

        // Жёсткая защита: даже если конфиг перепутан и подсунут не sandbox,
        // а реальный тенант — тест должен упасть сразу, а не молча выполниться
        // против прод-данных клиента.
        if (! str_contains($tenant->name ?? '', 'Sandbox')) {
            throw new RuntimeException(
                "Тенант '{$sandboxTenantId}' не помечен как sandbox в поле name. " .
                'Останавливаюсь, чтобы не выполнить тест против чужой БД по ошибке.'
            );
        }

        tenancy()->initialize($tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();

        parent::tearDown();
    }
}
