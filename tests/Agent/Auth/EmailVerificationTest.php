<?php

namespace Tests\Agent\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Agent\TenantHttpTestCase;

/**
 * См. докблок AuthenticationTest.
 *
 * verification.verify защищён middleware 'signed' (routes/auth.php), а не
 * 'signed:relative' — подпись считается по АБСОЛЮТНОМУ URL (см.
 * UrlGenerator::hasCorrectSignature(), $absolute по умолчанию true).
 * Обычный temporarySignedRoute(..., absolute:true) подписал бы URL с
 * central-доменом (APP_URL) — при обращении по домену тенанта Host не
 * совпал бы с тем, что зашито в подпись, и подпись сломалась бы. forceRootUrl()
 * временно подменяет корень генерации на домен sandbox-тенанта, чтобы и
 * подпись, и реально запрашиваемый URL совпадали — единственно верный
 * способ выпустить подписанную ссылку НЕ на central-домен.
 */
class EmailVerificationTest extends TenantHttpTestCase
{
    #[Test]
    public function email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get($this->tenantUrl('/verify-email'));

        $response->assertStatus(200);
    }

    #[Test]
    public function email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = $this->signedVerificationUrl($user, sha1($user->email));

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect($this->tenantUrl(route('dashboard', absolute: false)).'?verified=1');
    }

    #[Test]
    public function email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = $this->signedVerificationUrl($user, sha1('wrong-email'));

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function signedVerificationUrl(User $user, string $hash): string
    {
        URL::forceRootUrl($this->tenantUrl());

        try {
            return URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                ['id' => $user->id, 'hash' => $hash],
            );
        } finally {
            // Не даём подмене корня утечь в следующий тест этого же процесса.
            URL::forceRootUrl(null);
        }
    }
}
