# Queue operations

Uncovr uses Laravel's database queue in the private `laravel` PostgreSQL schema.
The queue is an internal backend concern: neither the portal nor future mobile
clients connect to queue tables directly.

## Queue layout

- `emails` handles verification, password reset and organization invitations.
- `publishing` handles scheduled release publication.
- `default` is reserved for general background work.

All application jobs are dispatched after the surrounding database transaction
commits. Email jobs time out after 60 seconds and publishing jobs after 120
seconds. Both allow three attempts with delays of 60 seconds, 5 minutes and 15
minutes. A timeout is a terminal exception for that attempt. The database
`retry_after` value is 180 seconds and must remain greater than the longest
worker timeout, otherwise two workers can process the same job concurrently.

PostgreSQL uses partial indexes for pending and reserved jobs. Laravel's
database driver uses `FOR UPDATE SKIP LOCKED` on supported PostgreSQL versions,
so multiple workers can claim separate jobs without blocking one another.

## Database connection

A persistent Laravel server or worker should use the Supabase direct connection
when its host supports IPv6. On IPv4-only hosts, use the Shared Pooler in
**session mode** on port 5432. Do not switch a persistent queue worker to the
transaction-mode port 6543 without also reviewing prepared statements and the
deployment architecture.

The queue uses `DB_QUEUE_CONNECTION`; production currently points this to the
same `pgsql` connection as the application. Credentials remain in the deployment
secret manager and must never be placed in Git.

## Production processes

Run the web process, scheduler and queue worker as separately supervised
processes. A process manager must restart the worker after crashes and server
reboots. A safe baseline worker command is:

```bash
php artisan queue:work database \
  --queue=emails,publishing,default \
  --sleep=3 \
  --tries=3 \
  --timeout=120 \
  --max-time=3600 \
  --memory=256
```

Run exactly one scheduler process:

```bash
php artisan schedule:work
```

Alternatively, invoke `php artisan schedule:run` once per minute from the
hosting platform. Do not run both approaches. Worker shutdown grace periods
must exceed 120 seconds. After every deployment, run:

```bash
php artisan queue:restart
```

This lets long-running workers finish their current job before loading the new
release. The one-hour `--max-time` also recycles workers to release accumulated
resources.

## Monitoring and failed jobs

The scheduler checks `emails`, `publishing` and `default` every minute. A queue
with 100 or more entries emits a structured `queue.busy` warning. Exhausted jobs
are written to `failed_jobs` and emit `queue.job_failed` with only operational
metadata: connection, queue, job identifiers, job class, attempts and exception
class. Payloads, exception messages, email bodies, tokens and provider responses
are deliberately excluded from this log event.

Inspect failures with:

```bash
php artisan queue:failed
php artisan queue:monitor database:emails,database:publishing,database:default --max=100
```

Before retrying a failure, identify and fix the cause. Retry a specific UUID:

```bash
php artisan queue:retry FAILED_JOB_UUID
```

Do not routinely use `queue:retry all`; it can repeat a systemic failure and
create provider traffic. Forget an irrecoverable, reviewed failure with
`queue:forget FAILED_JOB_UUID`.

Failed jobs are retained for 30 days and completed, cancelled or unfinished
batch metadata for 7 days. The scheduler prunes both daily. These windows can be
changed through `QUEUE_FAILED_RETENTION_HOURS` and
`QUEUE_BATCH_RETENTION_HOURS` after reviewing support and audit requirements.

External delivery status, alert routing and provider-specific Resend failures
are completed in B8.2 and B8.3. B8.1 guarantees durable storage, bounded retries,
safe failure visibility and an explicit production worker contract.
