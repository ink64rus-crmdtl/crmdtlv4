<?php

namespace Tests\Agent\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Agent\TenantHttpTestCase;

/**
 * См. докблок AuthenticationTest.
 */
class PasswordUpdateTest extends TenantHttpTestCase
{
    #[Test]
    public function password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from($this->tenantUrl('/profile'))
            ->put($this->tenantUrl('/password'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->tenantUrl('/profile'));

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    #[Test]
    public function correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from($this->tenantUrl('/profile'))
            ->put($this->tenantUrl('/password'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect($this->tenantUrl('/profile'));
    }
}
