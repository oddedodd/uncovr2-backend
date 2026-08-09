# Production observability

Production writes structured JSON to stderr. The hosting platform must collect
that stream, retain it according to the privacy policy, and route error-level
events to the selected monitoring provider. Application logs include the
`X-Request-ID` correlation value; queue records add job UUIDs, and Resend events
are correlated by `svix-id` and provider message ID.

The logging processor recursively redacts secret-like fields, bearer tokens,
Resend/Supabase secret prefixes, email addresses and exception messages before
they reach any configured handler. Never add request bodies, authorization
headers, reset links, signed URLs, email bodies or raw provider payloads to log
context. Metrics and alerts use counts, rates, event types and opaque IDs only.

## Required production wiring

- `LOG_CHANNEL=stack`, `LOG_STACK=stderr`, `LOG_LEVEL=info`;
- `OPERATIONS_ALERT_CHANNEL` points to a monitored production channel;
- the scheduler runs once per minute and supervised queue workers remain active;
- the platform alerts on unhandled exceptions, HTTP 5xx rate and worker exits;
- `operations:check` alerts on failed jobs, provider failures, bounce rate and
  complaint rate using the thresholds in `config/operations.php`;
- health probes use `/api/v1/health/live` and `/api/v1/health/ready`.

Tune thresholds only from observed production volume and record the reason in a
change review. During an incident, correlate by opaque IDs and follow
`OPERATIONS_RUNBOOK.md`; do not weaken redaction to obtain more detail.

## Verification

Before release, run:

```bash
php artisan operations:check --json --no-alert
php artisan release:check --production-like
```

Then emit a controlled non-sensitive test error in staging and confirm it
appears in the monitoring provider with a request ID, without personal data or
secrets. A provider-specific connection is deployment configuration; the
backend stays vendor-neutral and sends JSON through stderr and the configured
Laravel alert channel.
