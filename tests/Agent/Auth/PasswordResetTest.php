<?php

namespace Tests\Agent\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Agent\TenantHttpTestCase;

/**
 * См. докблок AuthenticationTest. reset-password/{token} и login — тоже
 * тенантские маршруты, их тоже нет на central-домене.
 */
class PasswordResetTest extends TenantHttpTestCase
{
    #[Test]
    public function reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get($this->tenantUrl('/forgot-password'));

        $response->assertStatus(200);
    }

    #[Test]
    public function reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post($this->tenantUrl('/forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post($this->tenantUrl('/forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get($this->tenantUrl('/reset-password/'.$notification->token));

            $response->assertStatus(200);

            return true;
        });
    }

    #[Test]
    public function password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post($this->tenantUrl('/forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post($this->tenantUrl('/reset-password'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect($this->tenantUrl(route('login', absolute: false)));

            return true;
        });
    }
}
