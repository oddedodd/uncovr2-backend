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
key, sender, or reply-to address. `RESEND_WEBHOOK_SECRET` is reserved for the
signed delivery-webhook work in B8 and must also remain outside Git.

Local development deliberately keeps `MAIL_MAILER=log`; a real Resend key is
not required for automated or day-to-day local work. Staging and production
must use separate `sending_access` keys restricted to `mail.uncovr.no`. The
controlled real-delivery check and its environment-specific key belong to
`B2.14`, not the automated B2.5 test suite.

Run a worker for transactional mail:

```bash
php artisan queue:work --queue=emails --tries=3
```

Mail is queued only after its database transaction commits. Retry delays are
60 seconds, 5 minutes, and 15 minutes. Exhausted jobs are visible in Laravel's
`failed_jobs` table. Every verification attempt has a deterministic Resend
idempotency key, so a retry of the same queued notification cannot create a
duplicate provider send.

## Verified Resend domain

The dedicated sending subdomain `mail.uncovr.no` was verified by Resend on
2026-08-08. A public DNS lookup on the same date confirmed:

- DKIM TXT at `resend._domainkey.mail.uncovr.no`;
- SPF TXT at `send.mail.uncovr.no` authorizing Amazon SES;
- MX return path at `send.mail.uncovr.no`, priority 10, in `eu-west-1`;
- organizational DMARC at `_dmarc.uncovr.no` with `p=quarantine` and aggregate
  reports handled by Domeneshop. In the absence of a more specific subdomain
  record, this policy also applies to `mail.uncovr.no`.

Do not replace the separate root SPF record used by Domeneshop mail. If Uncovr's
final domain or email provider changes, review all four records and update the
sender addresses and this document together.

The configured sender is `accounts@mail.uncovr.no`. The reply-to address
`support@uncovr.no` must resolve to a real, monitored inbox before the staging
smoke test. Staging and production use separate scoped Resend API keys whose
owners and rotation dates are recorded in the deployment secret manager.

The real staging delivery remains intentionally manual. Record its date,
recipient, Resend message ID, SPF result, DKIM result, DMARC result, and inbox
placement when completing B2.14.

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
