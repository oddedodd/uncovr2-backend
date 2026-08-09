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
RESEND_WEBHOOK_SECRET=whsec_...
RESEND_WEBHOOK_URL=https://api.uncovr.no/api/v1/webhooks/resend
RESEND_API_KEY_ROTATED_AT=YYYY-MM-DD
RESEND_WEBHOOK_SECRET_ROTATED_AT=YYYY-MM-DD
```

The application fails fast whenever the Resend mailer is enabled without an API
key, sender, or reply-to address. Production additionally requires a `whsec_`
webhook secret and an HTTPS callback URL. All secrets remain outside Git.

Local development deliberately keeps `MAIL_MAILER=log`; a real Resend key is
not required for automated or day-to-day local work. Staging and production
must use separate `sending_access` keys restricted to `mail.uncovr.no`. The
controlled real-delivery check and its environment-specific key belong to
`B2.14`, not the automated B2.5 test suite.

The production worker contract is documented in
[`QUEUE_OPERATIONS.md`](QUEUE_OPERATIONS.md). To process only transactional mail
during local diagnosis, run:

```bash
php artisan queue:work database --queue=emails --tries=3 --timeout=120
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
placement in the release record.

### B2.14 smoke-test record

On 2026-08-08 the guarded command sent one message from
`accounts@mail.uncovr.no` to `post@odde.org`:

- Resend message ID: `0dd0fa7f-a622-41c8-83fa-a81acfa721dd`
- smoke-test run ID: `01kzf987x882ajgea8bywj7ac1`
- API result: accepted by Resend
- delivery-event lookup: unavailable because the production key is correctly
  restricted to sending only
- inbox placement: confirmed by the owner of `post@odde.org` on 2026-08-08
- SPF, DKIM and DMARC: sending-domain and DNS configuration verified; the
  restricted sending key cannot retrieve received-message authentication headers

The command requires `--to` and an exactly matching `--confirm`, refuses to run
in the automated-test environment, and never sends through the queue. Run it
only for an explicitly approved controlled address:

```bash
php artisan email:resend-smoke-test \
  --to=controlled@example.com \
  --confirm=controlled@example.com
```

## Signed delivery webhooks

Create one Resend webhook endpoint pointing to
`https://api.uncovr.no/api/v1/webhooks/resend`, subscribe it to `email.sent`,
`email.delivery_delayed`, `email.delivered`, `email.bounced`,
`email.complained`, `email.suppressed` and `email.failed`, then copy its signing
secret to `RESEND_WEBHOOK_SECRET`. Laravel verifies the untouched request body
against `svix-id`, `svix-timestamp` and `svix-signature` before parsing it.
Invalid, expired, malformed and oversized requests are rejected.

`svix-id` is unique in the database, so retries are acknowledged but processed
once. A newer terminal outcome cannot be overwritten by a late `sent` or
`delivered` event. The database stores only provider message ID, delivery state,
event type and timestamps—never recipients, subject lines, email bodies or raw
webhook payloads.

Run `php artisan operations:check --json` to inspect queue failures, provider
failures, bounce rate and complaint rate. The scheduler runs the same check
every five minutes and emits redacted structured alerts after configured
thresholds are exceeded.

The production-like PostgreSQL CI job exercises valid, invalid, expired,
replayed, duplicate, out-of-order, delivered and bounced signed requests through
the real HTTP route. For a deployment smoke test, send to an approved address,
confirm a `delivered` event and use a Resend-supported bounce test recipient to
confirm a `bounced` event. Store only the message IDs and result in the release
record. Do not use a real customer address for bounce testing.

Rotate API and webhook secrets separately: add the replacement in Resend,
deploy it, verify one send or signed event, revoke the old credential, and update
the matching `*_ROTATED_AT` date. `release:check --production-like` fails when a
date is missing or older than the configured maximum age.

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
