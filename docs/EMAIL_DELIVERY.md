# Transactional email delivery

Uncovr sends account and security email through Laravel Mail and Notifications.
Resend is the production transport; local development uses Laravel's `log`
transport and automated tests use `array` or notification fakes. Tests and CI
must never contain a real API key or contact Resend.

## Application configuration

Keep these values in the deployment environment, never in Git:

```dotenv
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=accounts@mail.uncovr.no
MAIL_FROM_NAME=Uncovr
MAIL_REPLY_TO_ADDRESS=support@uncovr.no
MAIL_REPLY_TO_NAME="Uncovr support"
MAIL_QUEUE=emails
RESEND_API_KEY=re_...
```

The application fails fast whenever the Resend mailer is enabled without an API
key, sender, or reply-to address. `RESEND_WEBHOOK_SECRET` is reserved for the signed delivery-webhook
work in B8 and must also remain outside Git.

Run a worker for transactional mail:

```bash
php artisan queue:work --queue=emails --tries=3
```

Mail is queued only after its database transaction commits. Retry delays are
60 seconds, 5 minutes, and 15 minutes. Exhausted jobs are visible in Laravel's
`failed_jobs` table. Every verification attempt has a deterministic Resend
idempotency key, so a retry of the same queued notification cannot create a
duplicate provider send.

## Resend domain checklist

Use the dedicated sending subdomain `mail.uncovr.no`. If Uncovr's final domain
changes, update the sender addresses and this document together.

1. Add `mail.uncovr.no` in the Resend Domains dashboard.
2. Copy Resend's current SPF and DKIM records exactly into the authoritative DNS
   provider. Do not copy example values from documentation.
3. Wait until Resend reports the domain as verified.
4. Publish a DMARC TXT record for the sending domain. Begin with monitoring
   (`p=none`) and an actively monitored aggregate-report mailbox; tighten the
   policy only after legitimate traffic has been observed.
5. Confirm that `accounts@mail.uncovr.no` is accepted as the From address and
   that replies to `support@uncovr.no` reach a monitored inbox.
6. Use separate, scoped Resend API keys for staging and production and record
   their owners and rotation dates in the deployment secret manager.

The dashboard verification and a real staging delivery are intentionally
manual. Record the date, recipient, Resend message ID, SPF result, DKIM result,
DMARC result, and inbox placement when completing B2.5b and B2.14.

## Safe local preview and tests

With `APP_ENV=local`, start Laravel and open:

```text
http://localhost:8000/dev/mail/verify-email
```

The preview uses a non-functional example link and sends nothing. The template
contains semantic headings, a visible fallback URL, high-contrast action text,
and a separate plain-text view.

Run the automated email and registration coverage with:

```bash
php artisan test tests/Feature/Auth/RegistrationTest.php tests/Feature/Auth/EmailVerificationTest.php
```

The verification URL is signed, expires after 60 minutes by default, and
includes the current verification version. A resend increments the version,
invalidates every older link, and is rate limited. Successful links cannot be
replayed. Register and resend return the same neutral response regardless of
whether an account already exists.
