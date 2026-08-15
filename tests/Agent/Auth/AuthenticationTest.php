<?php

namespace Tests\Agent\Auth;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Agent\TenantHttpTestCase;

/**
 * Реальный HTTP-проход через /login, /logout на домене sandbox-тенанта
 * (routes/tenant.php → routes/auth.php, за InitializeTenancyByDomain).
 * Закрывает пробел, который раньше маскировали central-тесты того же имени
 * (tests/Feature/Auth/AuthenticationTest.php) — те били по голому /login
 * на central-домене, где этот маршрут в принципе не существует (см.
 * CLAUDE.md §2), и всегда получали 404 независимо от состояния кода.
 */
class AuthenticationTest extends TenantHttpTestCase
{
    #[Test]
    public function login_screen_can_be_rendered(): void
    {
        $response = $this->get($this->tenantUrl('/login'));

        $response->assertStatus(200);
    }

    #[Test]
    public function users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post($this->tenantUrl('/login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect($this->tenantUrl(route('dashboard', absolute: false)));
    }

    #[Test]
    public function users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post($this->tenantUrl('/login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    #[Test]
    public function users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post($this->tenantUrl('/logout'));

        $this->assertGuest();
        $response->assertRedirect($this->tenantUrl('/'));
    }
}
