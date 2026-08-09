# Backend release checklist

Run this checklist for staging and production releases. Store evidence with the
release record; never store secrets or raw provider payloads.

## Before deployment

1. Review the exact Git commit and migration diff.
2. Confirm the deployment target uses `Uncovr-db`, never the paused legacy
   project `Uncovr`.
3. Confirm a current backup/PITR point and the latest successful restore drill.
4. Run dependency audits, formatting, syntax checks and the complete test suite.
5. Run the production-like PostgreSQL CI job.
6. Confirm scoped Supabase and Resend credentials are present and within the
   configured rotation age.
7. Confirm `APP_DEBUG=false`, HTTPS URLs, stderr JSON logging, database queues,
   a scheduler and supervised workers.

## Database and integration checks

Run after migrations and before sending traffic:

```bash
php artisan migrate --force
php artisan release:check --production-like
php artisan operations:check --json --no-alert
```

`release:check` fails on pending migrations, missing domain tables, unsafe queue
timings, public Supabase-role grants, invalid PostgreSQL indexes, unindexed
foreign keys, stale Resend credentials or incomplete production integration
configuration.

In addition, run Supabase's Security and Performance Advisors on `Uncovr-db`.
Security findings must be resolved. Performance `unused_index` informational
items require traffic evidence before removing an index; they are expected in a
new or restored environment.

## Deployment

1. Put schema-breaking releases into the documented maintenance mode.
2. Deploy application code and run migrations once.
3. Run `php artisan queue:restart` so workers load the new release safely.
4. Confirm exactly one scheduler and the intended worker pools are running.
5. Run health, authentication, publishing, media and webhook smoke tests.
6. Confirm label and artist onboarding routes are registered and that the
   `artist_invitations` migration is present before enabling portal P3 flows.
7. Confirm Resend delivery status and inspect queue/error metrics.

## Rollback

Prefer a forward fix for migrations that have processed production data. Code
rollback is permitted only when the previous code is compatible with the new
schema. Never run `migrate:rollback` automatically in production. If data must
be restored, declare an incident and follow `OPERATIONS_RUNBOOK.md`.

## Release completion

The release is complete when `release:check` is ready, advisors are reviewed,
health remains stable through the observation window, queues drain normally,
and no abnormal provider, bounce or complaint alert is active.
