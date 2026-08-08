# Media, approval and publishing

This document describes the B5 backend contract. Laravel remains the only
identity and authorization authority. Supabase Auth is not used.

## Storage boundary

Two Supabase Files buckets are used:

| Bucket | Visibility | Purpose | Retention |
| --- | --- | --- | --- |
| `uncovr-private-media` | Private | Draft uploads, replacements and previews | Expired requests: first hourly cleanup after expiry. Superseded files: 7 days. |
| `uncovr-public-media` | Public | Versioned copies referenced by immutable publication snapshots | Retained while a publication version may be referenced. Removal is an explicit later retention operation. |

The bucket-level limit is 50 MB. Laravel applies the narrower per-kind limits
and MIME allowlists in `config/media.php`. The global Supabase Storage limit
must be at least 50 MB.

Laravel creates signed upload URLs with a backend-only Supabase secret key.
The portal receives the signed URL and short-lived upload token, never the
secret key. A signed upload URL is valid for two hours. The portal uploads with
`PUT` and the exact requested `Content-Type`, then calls the completion
endpoint. Laravel reads provider metadata and validates actual MIME type, byte
size and, for images, dimensions decoded from the file bytes.

Because Uncovr users authenticate with Laravel rather than Supabase Auth, no
`anon` or `authenticated` write policies are added to `storage.objects`.
Private reads, writes, copies and deletion go through the authorized Laravel
backend. The backend secret bypasses Storage RLS and must never be placed in a
browser, mobile app, log, database row or Git.

Configure the backend locally:

```dotenv
SUPABASE_URL=https://YOUR_PROJECT_REF.supabase.co
SUPABASE_SECRET_KEY=sb_secret_YOUR_BACKEND_ONLY_KEY
SUPABASE_STORAGE_PRIVATE_BUCKET=uncovr-private-media
SUPABASE_STORAGE_PUBLIC_BUCKET=uncovr-public-media
```

Create or reconcile the buckets after configuration:

```bash
php artisan config:clear
php artisan media:provision-storage --force
```

Use a named, separately rotatable secret key for this Laravel backend. The
legacy `SUPABASE_SERVICE_ROLE_KEY` remains a temporary fallback but should not
be used for new setup.

## Safe upload and replacement flow

1. Create the media record with its owner, kind, filename and intended MIME.
2. `POST /api/v1/media/{media}/uploads` creates a unique generation and signed URL.
3. Upload the object directly to the returned URL with `PUT`.
4. `POST /api/v1/media/{media}/uploads/{upload}/complete` verifies the stored object.
5. Only after successful verification does a transaction switch the media
   record to the new generation. The previous generation is marked superseded.
6. The hourly `media:prune-uploads` command removes expired requests and
   superseded objects after their retention period. Active objects are never pruned.

Private preview downloads use a separate 15-minute signed URL. Deleting a
media record first deletes its active object through the Storage API; direct
SQL deletion from the Supabase `storage` schema is never used.

## Release lifecycle

The supported state transitions are:

```text
draft/unpublished -> review -> published -> unpublished
                         \-> scheduled -> published
review --rejected--> draft
draft/unpublished -> archived
```

Editors can preview and submit editable releases. Label Admin, Artist Admin,
the managing Label Admin and superadmin can approve, reject, schedule, publish,
unpublish or archive inside their authorized scope. Draft content is locked
during review, scheduling and publication.

Submission stores a deterministic fingerprint of the content. Approval and
publication fail if the approved fingerprint no longer matches. Publishing
copies referenced verified media to a versioned public path and writes a
separate `release_publications` snapshot. B6 will read that snapshot rather
than the mutable draft tables. Unpublishing withdraws the active snapshot.

Scheduled releases are dispatched every minute to the queue with controlled
retries. Production must run both Laravel's scheduler and an appropriate queue
worker.

All sensitive transitions append to `release_activity_events`. Application
model guards and a PostgreSQL trigger reject update and deletion of those
records.
