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

## Profile images and release covers

Four image functions reuse the same `media` and `media_uploads` records:

| Function | Reference | Required owner |
| --- | --- | --- |
| Label logo | `organization_profiles.logo_media_id` | The same organization |
| Artist logo | `artist_profiles.logo_media_id` | The same artist |
| Artist image | `artist_profiles.image_media_id` | The same artist |
| Release cover | `releases.cover_media_id` | The release's organization or artist owner |

All references are nullable foreign keys with `ON DELETE SET NULL`. Laravel
accepts an attachment only when the Media record exists, has `kind=image`, has
completed verification with `status=ready`, and belongs to the required owner
scope. The API performs these checks from server-owned database values; client
MIME, dimensions, status and owner claims are never trusted. Setting a reference
to `null` detaches it without deleting the Media record.

The profile tables store only the internal Media foreign key. They never store
binary data, `storage_disk`, `storage_key`, a Supabase credential or a signed or
permanent private URL. Media cannot be deleted while referenced by any of these
four functions or by existing release content. Upload and verify a replacement
first, PATCH the new Media public ID, then delete the detached old Media through
`DELETE /api/v1/media/{media}` if it is no longer used.

### Required upload sequence

Image attachment happens after the label, artist or release exists and never
inside an onboarding transaction.

For small profile images, prefer the single-request endpoints. Laravel receives
the multipart file, validates the actual MIME type and decoded dimensions
locally, uploads one verified object to private Storage, creates the Media
record and attaches it in one operation:

```http
POST /api/v1/organizations/{organization}/logo
Content-Type: multipart/form-data

image=@logo.png
```

```http
POST /api/v1/artists/{artist}/logo
POST /api/v1/artists/{artist}/image
```

These endpoints are optimized for logos and artist profile images where the
file is small and orchestration latency is more expensive than sending the file
through Laravel once.

For larger reusable assets, or clients that need direct-to-Storage upload,
continue to use the signed upload sequence:

1. `POST /api/v1/media` with the correct `owner_type`, `owner_id`, `kind=image`,
   filename and intended MIME type.
2. `POST /api/v1/media/{media}/uploads`.
3. `PUT` the bytes to the returned short-lived Supabase signed upload URL using
   the exact returned MIME type.
4. `POST /api/v1/media/{media}/uploads/{upload}/complete`; Laravel downloads and
   verifies actual file MIME, byte size and decoded dimensions before setting
   `status=ready`.
5. PATCH the profile or release with the verified Media public ID.

The image defaults are 25 MiB, maximum width and height 12,000 pixels, maximum
100,000,000 pixels total, and MIME types JPEG, PNG, WebP and AVIF. They are not
aspect-ratio requirements. Deployments may change them with
`MEDIA_IMAGE_MAX_BYTES`, `MEDIA_IMAGE_MAX_WIDTH`, `MEDIA_IMAGE_MAX_HEIGHT`,
`MEDIA_IMAGE_MAX_PIXELS` and comma-separated `MEDIA_IMAGE_MIME_TYPES`. The
bucket limit must remain at least as large as `MEDIA_IMAGE_MAX_BYTES`.

### Profile request and response contracts

Label Admin or superadmin uploads a label logo with multipart form data:

```http
POST /api/v1/organizations/{organization}/logo
Content-Type: multipart/form-data

image=@logo.png
```

The response is the updated label resource. Label Admin or superadmin can also
attach an already verified image, or remove the logo:

```http
PATCH /api/v1/organizations/{organization}
Content-Type: application/json

{"logo_media_id":"01PUBLIC_MEDIA_ULID"}
```

Use `{"logo_media_id":null}` to detach it. The relevant response fields are:

```json
{
  "data": {
    "profile": {
      "logo_media_id": "01PUBLIC_MEDIA_ULID",
      "logo_media": {
        "id": "01PUBLIC_MEDIA_ULID",
        "status": "ready",
        "mime_type": "image/png",
        "width": 1200,
        "height": 1200
      }
    }
  }
}
```

Artist Admin, a managing Label Admin or superadmin uploads artist images with
multipart form data:

```http
POST /api/v1/artists/{artist}/logo
Content-Type: multipart/form-data

image=@logo.png
```

```http
POST /api/v1/artists/{artist}/image
Content-Type: multipart/form-data

image=@portrait.png
```

They can also attach already verified images:

```http
PATCH /api/v1/artists/{artist}
Content-Type: application/json

{
  "logo_media_id": "01LOGO_MEDIA_ULID",
  "image_media_id": "01IMAGE_MEDIA_ULID"
}
```

Each field independently accepts `null`. `profile.logo_media_id` and
`profile.image_media_id` contain the public IDs, while `profile.logo_media` and
`profile.image_media` contain the same safe five-field Media object shown above.
Artist User may retain existing text-profile permissions but cannot change
either image field.

Release create and update retain `cover_media_id`:

```json
{"cover_media_id":"01COVER_MEDIA_ULID"}
```

`ReleaseResource` returns `cover_media_id` and a matching safe `cover_media`
object. `null` removes the cover reference. Every other organization, artist and
release response field remains unchanged.

Attachment validation returns HTTP 422 with an error keyed by the submitted
field when Media is missing, non-image, not ready or cross-scope. Missing
authentication returns 401 and insufficient scope returns 403. A referenced
Media deletion returns 422 keyed by `media`.

### Short-lived viewing URLs

Single images continue to use the authorized endpoint:

```http
GET /api/v1/media/{media}/download
```

For list views, the portal should make one provider-neutral Laravel request for
up to 100 Media IDs instead of one request per row:

```http
POST /api/v1/media/downloads
Content-Type: application/json

{"media_ids":["01FIRST_MEDIA_ULID","01SECOND_MEDIA_ULID"]}
```

```json
{
  "data": {
    "expires_in": 900,
    "items": [
      {
        "media_id": "01FIRST_MEDIA_ULID",
        "url": "https://short-lived-signed-storage-url.example"
      }
    ]
  }
}
```

Laravel authorizes every requested Media record and rejects the whole batch if
one record is missing, unauthorized or not ready. The batch API may create the
provider URLs individually behind the server boundary, but it is one portal API
call and remains independent of the Storage provider. The response never
contains a bucket, object key, secret or permanent private URL. Configure the
batch size with `MEDIA_BATCH_DOWNLOAD_LIMIT` and expiry with
`MEDIA_DOWNLOAD_TTL_SECONDS`.

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
