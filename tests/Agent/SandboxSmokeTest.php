<?php

namespace Tests\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class SandboxSmokeTest extends TenantAgentTestCase
{
    #[Test]
    public function it_is_actually_connected_to_the_sandbox_tenant_database(): void
    {
        // Если tenancy()->initialize() отработал верно, текущее подключение
        // должно смотреть на tenanttenant_agent_sandbox, а не на central (crmdtlv4).
        $currentDatabase = DB::connection()->getDatabaseName();

        $this->assertStringContainsString('tenant_agent_sandbox', $currentDatabase);
        $this->assertNotEquals('crmdtlv4', $currentDatabase);
    }

    #[Test]
    public function it_sees_core_tables_from_the_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::hasTable('users'),
            'Таблица users должна существовать в схеме sandbox-тенанта.'
        );
        $this->assertTrue(
            Schema::hasTable('branches'),
            'Таблица branches должна существовать в схеме sandbox-тенанта.'
        );
    }
}
