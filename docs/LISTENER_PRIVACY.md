# Listener domain, privacy and notifications

This document describes the B7 Laravel contract. Listener records live in the
private `laravel` PostgreSQL schema and are available only through authenticated
Laravel endpoints. Supabase Auth and the Supabase Data API are not used.

Every authenticated response receives `Cache-Control: no-store, private`.
Ownership filters are applied in the database query, so an unknown and a
foreign private identifier both return 404.

## Library and collections

Artist follows and release favorites use idempotent `PUT` and `DELETE`
operations below `/api/v1/me`. Their database pairs are unique, making repeated
requests safe during mobile synchronization. Lists use opaque cursor
pagination. Track favorites and track collection targets are retained only for
legacy listener compatibility and are outside the portal release-builder
contract.

Private collections support create, list, show, update and delete. A complete
ordered item list is replaced atomically with:

```http
PUT /api/v1/me/collections/{collection_id}/items
```

```json
{
  "items": [
    {"type": "track", "id": "01..."},
    {"type": "release", "id": "01..."}
  ]
}
```

New follows, favorites and collection items must reference currently available
published content. If saved content later becomes unavailable, the collection
keeps its stable ID and position but returns `available: false` without reading
mutable draft fields. Favorite lists omit unavailable content.

## Preferences, consent and notifications

Optional topics are `artist_updates`, `release_updates`, `product_updates` and
`marketing`. Email and push marketing cannot be enabled unless the latest
separate consent record for that channel is granted. Required account and
security email is not represented by an opt-out preference.
Withdrawing a marketing-channel consent atomically disables the corresponding
stored preference; enabling it again requires a new affirmative consent record.

Registration requires explicit acceptance of the current terms and privacy
versions. Marketing decisions remain optional and separate. Later decisions
for marketing email, marketing push and analytics are append-only records. The
client never chooses the policy version; Laravel records the configured current
version. Consent rows cannot be updated or deleted.

In-app notifications are private, cursor-paginated and support marking one or
all rows as read. Required notifications bypass optional topic preferences.

## Push devices

A push device is registered against an owned, active mobile device session.
The token is encrypted with `APP_KEY`; only its SHA-256 hash is indexed for
deduplication. API responses and exports never contain the token. Revoking a
device session, logging out or requesting account deletion disables its push
devices.

Actual APNs/FCM delivery is intentionally outside B7. B7 establishes secure
registration, preference and lifecycle contracts for the later Expo client.

## Export and deletion

`GET /api/v1/me/privacy/export` returns a downloadable JSON copy of the owning
listener's account and listener-domain data. It excludes password hashes,
tokens, IP hashes and data belonging to other users.

Account deletion requires the current password and the literal confirmation
`DELETE`. Laravel immediately revokes sessions and disables push delivery, then
schedules anonymization after `PRIVACY_DELETION_GRACE_DAYS` (30 by default).
The user may sign in again and cancel during the grace period. The daily
`privacy:process-account-deletions` command then:

- removes private library, collection, preference, notification and membership data;
- removes the original email and replaces the display name;
- rotates the password and prevents access through the old identity;
- retains append-only consent proof and the deletion completion record;
- leaves operational backups subject to the separately documented backup
  retention policy.

## Aggregate insights

Authorized label and artist users can read only total follower and favorite
counts through their scope's `listener-insights` endpoint. Responses never
include listener IDs, email addresses, collections or individual activity.
