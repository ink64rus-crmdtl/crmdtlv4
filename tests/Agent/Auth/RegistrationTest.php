<?php

namespace Tests\Agent\Auth;

use PHPUnit\Framework\Attributes\Test;
use Tests\Agent\TenantHttpTestCase;

/**
 * См. докблок AuthenticationTest — тот же принцип, реальный HTTP-проход
 * через домен sandbox-тенанта вместо central-домена, где /register не
 * существует.
 */
class RegistrationTest extends TenantHttpTestCase
{
    #[Test]
    public function registration_screen_can_be_rendered(): void
    {
        $response = $this->get($this->tenantUrl('/register'));

        $response->assertStatus(200);
    }

    #[Test]
    public function new_users_can_register(): void
    {
        $response = $this->post($this->tenantUrl('/register'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect($this->tenantUrl(route('dashboard', absolute: false)));
    }
}
