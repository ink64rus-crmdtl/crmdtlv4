<?php

namespace Tests\Agent\Auth;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Agent\TenantHttpTestCase;

/**
 * См. докблок AuthenticationTest.
 */
class PasswordConfirmationTest extends TenantHttpTestCase
{
    #[Test]
    public function confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get($this->tenantUrl('/confirm-password'));

        $response->assertStatus(200);
    }

    #[Test]
    public function password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post($this->tenantUrl('/confirm-password'), [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    #[Test]
    public function password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post($this->tenantUrl('/confirm-password'), [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
