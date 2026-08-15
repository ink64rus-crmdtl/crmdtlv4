<?php

namespace Tests\Agent;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Реальный HTTP-проход через /profile на домене sandbox-тенанта — см.
 * докблок Auth/AuthenticationTest.php. Оригинал (tests/Feature/ProfileTest.php)
 * бил по central-домену, где /profile не существует (маршрут объявлен
 * в routes/tenant.php, за InitializeTenancyByDomain).
 */
class ProfileTest extends TenantHttpTestCase
{
    #[Test]
    public function profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get($this->tenantUrl('/profile'));

        $response->assertOk();
    }

    #[Test]
    public function profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch($this->tenantUrl('/profile'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->tenantUrl('/profile'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    #[Test]
    public function email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch($this->tenantUrl('/profile'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->tenantUrl('/profile'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    #[Test]
    public function user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete($this->tenantUrl('/profile'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->tenantUrl('/'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    #[Test]
    public function correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from($this->tenantUrl('/profile'))
            ->delete($this->tenantUrl('/profile'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect($this->tenantUrl('/profile'));

        $this->assertNotNull($user->fresh());
    }
}
