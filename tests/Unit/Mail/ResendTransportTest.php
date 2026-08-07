<?php

namespace Tests\Unit\Mail;

use App\Mail\Transport\ResendTransport;
use PHPUnit\Framework\TestCase;
use Resend\Client;
use Resend\Contracts\Transporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use Resend\ValueObjects\Transporter\Payload;
use Symfony\Component\Mime\Email;

class ResendTransportTest extends TestCase
{
    public function test_it_sends_the_internal_key_as_a_resend_http_idempotency_header(): void
    {
        $fakeTransporter = new class implements Transporter
        {
            public ?Payload $payload = null;

            public function request(Payload $payload): array
            {
                $this->payload = $payload;

                return ['id' => 'email_test_123'];
            }
        };
        $transport = new ResendTransport(new Client($fakeTransporter));
        $email = (new Email)
            ->from('accounts@mail.uncovr.no')
            ->to('artist@example.com')
            ->subject('Verify')
            ->text('Verify your email')
            ->html('<p>Verify your email</p>');
        $email->getHeaders()->addTextHeader(
            'X-Uncovr-Resend-Idempotency-Key',
            'verify-email-deterministic-key',
        );

        $transport->send($email);

        $this->assertNotNull($fakeTransporter->payload);
        $request = $fakeTransporter->payload->toRequest(
            BaseUri::from('api.resend.com'),
            Headers::withAuthorization(ApiKey::from('test-api-key')),
        );
        $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('verify-email-deterministic-key', $request->getHeaderLine('Idempotency-Key'));
        $this->assertArrayNotHasKey('X-Uncovr-Resend-Idempotency-Key', $payload['headers']);
    }
}
