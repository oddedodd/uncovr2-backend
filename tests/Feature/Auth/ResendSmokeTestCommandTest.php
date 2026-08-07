<?php

namespace Tests\Feature\Auth;

use App\Mail\AuthSmokeTestMail;
use Tests\TestCase;

class ResendSmokeTestCommandTest extends TestCase
{
    public function test_real_smoke_test_is_disabled_in_automated_tests(): void
    {
        $this->artisan('email:resend-smoke-test', [
            '--to' => 'post@odde.org',
            '--confirm' => 'post@odde.org',
        ])
            ->expectsOutput('Real email is disabled in the testing environment.')
            ->assertFailed();
    }

    public function test_smoke_mail_has_html_text_and_a_deterministic_idempotency_key(): void
    {
        $mail = new AuthSmokeTestMail('01testsmokerun');
        $content = $mail->content();
        $headers = $mail->headers();

        $this->assertSame('mail.auth.smoke-test', $content->view);
        $this->assertSame('mail.auth.smoke-test-text', $content->text);
        $this->assertSame(
            'auth-smoke-01testsmokerun',
            $headers->text['X-Uncovr-Resend-Idempotency-Key'],
        );
        $this->assertStringContainsString(
            'E-postleveringen fungerer',
            view($content->view, $content->with)->render(),
        );
    }
}
