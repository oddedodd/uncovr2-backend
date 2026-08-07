<?php

namespace App\Console\Commands;

use App\Mail\AuthSmokeTestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendResendSmokeTest extends Command
{
    protected $signature = 'email:resend-smoke-test
        {--to= : Controlled recipient address}
        {--confirm= : Repeat the exact recipient address to authorize the external send}
        {--wait=30 : Seconds to wait for a terminal Resend delivery event}';

    protected $description = 'Send one explicitly confirmed real email through Resend';

    public function handle(): int
    {
        if ($this->laravel->environment('testing')) {
            $this->error('Real email is disabled in the testing environment.');

            return self::FAILURE;
        }

        if (config('mail.default') !== 'resend') {
            $this->error('MAIL_MAILER must be resend for this smoke test.');

            return self::FAILURE;
        }

        $recipient = trim((string) $this->option('to'));
        $confirmation = trim((string) $this->option('confirm'));

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid --to address.');

            return self::INVALID;
        }

        if (! hash_equals($recipient, $confirmation)) {
            $this->error('--confirm must exactly match --to.');

            return self::INVALID;
        }

        $waitSeconds = max(0, min(60, (int) $this->option('wait')));
        $runId = strtolower((string) Str::ulid());
        $sent = Mail::to($recipient)->send(new AuthSmokeTestMail($runId));

        if ($sent === null) {
            $this->error('The mail transport did not return a sent message.');

            return self::FAILURE;
        }

        $messageId = $sent->getMessageId();
        $this->info("Resend message ID: {$messageId}");
        $this->line("Smoke-test run ID: {$runId}");

        if ($waitSeconds === 0) {
            return self::SUCCESS;
        }

        $client = \Resend::client(config('services.resend.key'));
        $deadline = time() + $waitSeconds;
        $lastEvent = 'sent';

        try {
            do {
                $email = $client->emails->get($messageId);
                $lastEvent = (string) ($email->last_event ?? 'sent');

                if (in_array($lastEvent, ['delivered', 'bounced', 'failed', 'suppressed', 'complained'], true)) {
                    break;
                }

                if (time() < $deadline) {
                    sleep(2);
                }
            } while (time() < $deadline);
        } catch (Throwable $exception) {
            $this->warn('The email was accepted, but this scoped API key cannot read delivery events.');
            $this->line('Confirm inbox placement in the recipient mailbox or Resend dashboard.');

            return self::SUCCESS;
        }

        $this->line("Resend last event: {$lastEvent}");

        if ($lastEvent === 'delivered') {
            $this->info('The recipient mail server accepted the smoke-test email.');

            return self::SUCCESS;
        }

        if (in_array($lastEvent, ['bounced', 'failed', 'suppressed', 'complained'], true)) {
            $this->error("Resend reported a terminal failure event: {$lastEvent}");

            return self::FAILURE;
        }

        $this->warn('Delivery is not terminal yet; check this message ID in Resend.');

        return self::SUCCESS;
    }
}
