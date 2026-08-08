# Public content API

This document describes the B6 public catalog contract. All endpoints are
available below `/api/v1/public`, require no authentication and use the
`public` rate limiter.

## Source of truth and visibility

Release and track responses are produced only from the current, immutable
`release_publications` snapshot. A publication is public only while:

- the publication has not been withdrawn;
- its release is in the `published` state;
- its owner is active; and
- its primary artist is active.

Labels and artists appear only when they are active and are connected to at
least one current publication. Mutable drafts, approval metadata, editors,
memberships, users, storage keys and internal timestamps are never selected by
the public presenter.

## Endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/public/labels` | Search and paginate public labels. |
| GET | `/public/labels/{id}` | Label profile and recent releases. |
| GET | `/public/artists` | Search and paginate public artists. |
| GET | `/public/artists/{id}` | Artist profile and recent releases. |
| GET | `/public/releases` | Search and paginate current publications. |
| GET | `/public/releases/recent` | Publications ordered newest first. |
| GET | `/public/releases/featured` | Superadmin-curated current publications. |
| GET | `/public/releases/{release_id}` | Frozen public release detail. |
| GET | `/public/tracks/{track_id}` | Frozen public track detail and release summary. |

List endpoints accept `filter[search]`, `page[size]`, `page[after]` and
`page[before]`. Page size defaults to 25 and is limited to 100. Cursors are
opaque and must be returned unchanged by clients.

Superadmins curate featured releases with
`PATCH /api/v1/releases/{release_id}/featured` and a boolean `featured` body.
Only currently published releases can be featured.

## Caching

Successful public responses include an ETag and:

```text
Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=600
```

The server cache is versioned. Publishing, unpublishing, featuring, profile
updates and artist/label suspension advance the version so stale catalog data
is no longer served. Matching `If-None-Match` requests receive `304 Not
Modified`.

PostgreSQL uses GIN full-text indexes for catalog search. Tests use the same
contract through a SQLite-compatible `LIKE` fallback.
