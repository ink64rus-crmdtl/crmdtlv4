<?php

namespace Tests\Agent;

use PHPUnit\Framework\Attributes\Test;

/**
 * Замена tests/Feature/ExampleTest.php — тот бил по central-домену '/',
 * а этот маршрут (Welcome-страница) существует только внутри
 * routes/tenant.php, за InitializeTenancyByDomain. См. докблок
 * Auth/AuthenticationTest.php — та же причина 404, тот же принцип фикса.
 */
class WelcomePageTest extends TenantHttpTestCase
{
    #[Test]
    public function welcome_page_returns_a_successful_response(): void
    {
        $response = $this->get($this->tenantUrl('/'));

        $response->assertStatus(200);
    }
}
