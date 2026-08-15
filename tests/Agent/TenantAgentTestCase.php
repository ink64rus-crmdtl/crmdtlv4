<?php

namespace Tests\Agent;

use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
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
 * Транзакция с откатом на РЕАЛЬНОМ подключении 'tenant', НЕ через трейт
 * Illuminate\Foundation\Testing\DatabaseTransactions — тот раньше здесь
 * использовался, но был живым багом: его хук запускается автоматически
 * изнутри parent::setUp() (см. InteractsWithTestCaseLifecycle::
 * setUpTheTestEnvironment()), то есть ДО tenancy()->initialize() ниже —
 * в этот момент database.default ещё указывает на central-подключение
 * (DatabaseTenancyBootstrapper переключает его на 'tenant' только внутри
 * initialize()). Трейт с connectionsToTransact()=[null] открывал транзакцию
 * на central-БД (где и так ничего не пишется), а РЕАЛЬНЫЕ записи в
 * tenant_agent_sandbox уходили без транзакции вообще — коммитились
 * НАВСЕГДА. Обнаружено эмпирически (создана пробная запись, тест прошёл,
 * процесс завершился — запись осталась в БД), не только по чтению кода:
 * PayrollCalculationTest/WorkOrderVatCalculationTest годами копили мусор в
 * sandbox, никак не проявляя себя, потому что сами тесты проходили —
 * поэтому НЕ полагайся при проверке транзакционности на "тест зелёный",
 * проверяй именно возврат в исходное состояние БД после прогона.
 */
abstract class TenantAgentTestCase extends TestCase
{
    private ?Connection $tenantTransaction = null;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $sandboxTenantId = env('AI_AGENT_SANDBOX_TENANT', 'tenant_agent_sandbox');

        $tenant = Tenant::find($sandboxTenantId);

        if (! $tenant) {
            throw new RuntimeException(
                "Sandbox-тенант '{$sandboxTenantId}' не найден в central БД. ".
                'Проверь, что он создан (см. Часть 5 инструкции по деплою LarAgent) '.
                'и что тесты запущены через --configuration=phpunit.tenant.xml, '.
                'а не через обычный phpunit.xml (там DB_DATABASE=crmdtlv4 не будет виден).'
            );
        }

        // Жёсткая защита: даже если конфиг перепутан и подсунут не sandbox,
        // а реальный тенант — тест должен упасть сразу, а не молча выполниться
        // против прод-данных клиента.
        if (! str_contains($tenant->name ?? '', 'Sandbox')) {
            throw new RuntimeException(
                "Тенант '{$sandboxTenantId}' не помечен как sandbox в поле name. ".
                'Останавливаюсь, чтобы не выполнить тест против чужой БД по ошибке.'
            );
        }

        $this->tenant = $tenant;

        tenancy()->initialize($tenant);

        // Открывается ЗДЕСЬ, после переключения на tenant-подключение —
        // связка называется буквально 'tenant' (см. DatabaseManager::
        // connectToTenant() в stancl/tenancy), это не зависит от того, как
        // называется central-подключение в .env/phpunit.tenant.xml.
        $this->tenantTransaction = DB::connection('tenant');
        $this->tenantTransaction->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->tenantTransaction?->rollBack();
        $this->tenantTransaction = null;

        tenancy()->end();

        parent::tearDown();
    }

    /**
     * Полный URL на реальном домене sandbox-тенанта — для HTTP-тестов,
     * которым нужен настоящий проход через InitializeTenancyByDomain (а не
     * прямой tenancy()->initialize() в PHP, как у остальных Agent-тестов).
     * Домен берётся из БД, а не хардкодится строкой — переживёт смену
     * домена sandbox-тенанта без правки тестов.
     */
    protected function tenantUrl(string $path = ''): string
    {
        $domain = $this->tenant->domains()->value('domain');

        if (! $domain) {
            throw new RuntimeException("У sandbox-тенанта '{$this->tenant->id}' нет ни одного домена — не могу построить URL для HTTP-теста.");
        }

        // ltrim('/') превращает '/' в '' — иначе tenantUrl('/') давал бы
        // конечный слэш ('http://domain/'), а реальные редиректы на корень
        // (redirect('/')) отдают Location без него ('http://domain').
        $path = ltrim($path, '/');

        return 'http://'.$domain.($path !== '' ? '/'.$path : '');
    }
}
