# Operations, backup and incident runbook

This runbook covers the Laravel API, the active Supabase project `Uncovr-db`,
Supabase Storage and Resend. The paused legacy project named `Uncovr` is never a
restore source or deployment target.

## Ownership and targets

- Laravel owns authentication, authorization, queue processing and domain data.
- PostgreSQL tables are in the private `laravel` schema in `Uncovr-db`.
- Supabase Storage holds media objects separately from PostgreSQL.
- Resend sends transactional email and posts signed delivery events to Laravel.

Before a public production launch, move `Uncovr-db` off the Free plan or operate
and verify an independent encrypted off-site logical-backup schedule. Supabase
documents automatic daily backups for paid plans and recommends regular logical
exports for Free projects. Database backups contain Storage metadata, not the
actual Storage objects, so object backup and restore are separate procedures.

Production targets after enabling PITR are:

- RPO: at most 2 hours for database data;
- RTO: at most 4 hours for the API and database;
- media-object recovery: at most 24 hours;
- restore drill: quarterly and before every material infrastructure migration.

## Backup procedure

1. Confirm the project selector says `Uncovr-db` and record the project ref.
2. Confirm the latest Supabase backup or PITR point in **Database → Backups**.
3. For an off-site logical backup, use the direct database connection and a
   current `pg_dump`. Never put its password in shell history or the repository.
4. Encrypt the dump before upload to the restricted backup location.
5. Export or replicate both private and public Storage buckets. A database dump
   alone is not a media backup.
6. Record timestamp, backup type, schema version, object count, encrypted file
   checksum and operator in the operational log.
7. Retain backups according to the active privacy and deletion policy. A backup
   expiry must eventually remove anonymized/deleted account remnants.

Never test restoration by overwriting the active project.

## Restore drill

1. Declare a drill and choose an isolated, access-restricted Supabase project.
2. Restore the selected physical/PITR backup or import the logical dump.
3. Reset custom-role passwords if the backup method did not preserve them.
4. Restore Storage objects to isolated buckets and reconcile object counts.
5. Deploy the exact application commit associated with the schema.
6. Run `php artisan migrate:status` and `php artisan release:check --production-like`.
7. Run the complete production-like test job and smoke-test health, login,
   publishing, media reads, queue processing and signed Resend webhook handling.
8. Verify `anon`, `authenticated` and `service_role` have no grants in `laravel`.
9. Record measured RPO/RTO, mismatches and corrective actions, then destroy the
   isolated restore environment and its secrets.

## Incident severity

- SEV-1: confirmed data exposure, destructive data loss, credential compromise,
  cross-tenant authorization bypass or total production outage.
- SEV-2: degraded authentication, publishing, queue, Storage or email delivery
  with a workaround or limited scope.
- SEV-3: isolated defect without security impact or material data loss.

## Incident response

1. Assign an incident lead and open a timestamped incident log.
2. Preserve evidence. Do not delete failed jobs, webhook records or relevant
   structured logs until the incident lead approves it.
3. Contain: revoke affected sessions and keys, pause workers or publishing when
   necessary, and restrict traffic without destroying evidence.
4. Identify the first bad event using request IDs, queue UUIDs, immutable
   publication activity, `svix-id` and provider message IDs. Never paste raw
   tokens, email bodies or secrets into the incident log.
5. Recover from the smallest safe scope. For database restore, account for API
   downtime and reconcile Storage separately.
6. Validate with `release:check`, the complete tests and targeted smoke tests.
7. Rotate exposed credentials, restart workers and monitor error, queue,
   bounce and complaint metrics during the observation window.
8. Publish an internal post-incident review covering root cause, timeline,
   customer impact, data impact and preventive changes.

## Provider-specific containment

- Supabase secret exposed: rotate the backend secret, redeploy Laravel, verify no
  client bundle contains it and audit Storage/database access.
- Database password exposed: reset it, update the secret manager, restart web and
  workers, then terminate stale connections if required.
- Resend key exposed: create a replacement scoped sending key, deploy it, revoke
  the old key and review sent-message activity.
- Resend webhook secret exposed: rotate it in the webhook endpoint and Laravel
  atomically; valid signatures using the retired secret must stop working.
- Laravel `APP_KEY` exposed: treat all encrypted queue payloads and stored tokens
  as compromised and follow a dedicated key-rotation migration plan. Do not
  simply replace it in-place.

## Recovery completion criteria

Recovery is complete only when health checks pass, queues are draining, no
pending migration exists, database security checks are clean, Storage objects
reconcile, signed webhooks process once, and the incident lead has recorded the
final observation-window result.
