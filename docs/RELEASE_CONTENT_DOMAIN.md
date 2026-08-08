# Release and content domain

B4 provides the private draft model used by the portal. Publishing, approval,
file uploads and Supabase Storage are intentionally deferred to B5.

## Release structure

A release is an `album`, `ep` or `single`, starts as `draft`, and has exactly
one owner scope: an organization or an artist. Collaborating artists are stored
separately in `release_artists`; every release always has one primary artist.

The editable tree is:

```text
release
├── artists
├── tracks
│   ├── pages
│   │   └── content blocks
│   ├── streaming links
│   └── credits
├── pages
│   └── content blocks
├── streaming links
└── credits
```

Positions are positive and unique within their parent. Database partial unique
indexes ignore soft-deleted rows so an old position can be reused without
destroying its historical record.

## Content blocks

Each block stores a validated JSONB payload and an incrementing version. Every
create and update writes an immutable row to `content_block_versions`.

- `heading`: text and heading level 1–6.
- `text`: body text.
- `image`: same-scope media ID, alt text and optional caption.
- `gallery`: 1–50 distinct same-scope media items with alt text.
- `video`: exactly one HTTPS URL or same-scope media ID.
- `quote`: text and optional attribution.
- `lyrics`: text and optional language code.

Unknown payload fields and cross-scope media references are rejected.

## Media and credits

Media records describe intended media independently of physical storage. They
start as `pending`; clients cannot provide storage disks, object keys or ready
states. B5 will authorize uploads and update those implementation fields.

Contributors belong to the same owner scope as the release. Credits connect a
contributor to either a release or track with a structured role and position.
Streaming links likewise belong to exactly one release or track, require HTTPS,
and allow one link per supported service per parent.

## History and deletion

`created_by_user_id`, `updated_by_user_id`, owner columns and release editor
assignments preserve attribution and responsibility. `release_activity_events`
record all release-tree mutations. Releases and addressable content records use
soft deletion; deleting a release hides its entire tree while retaining block
versions and activity events.

## API

All endpoints are authenticated under `/api/v1`. The main aggregate is exposed
through `/releases`; nested endpoints manage artists, editors, tracks, pages,
blocks, streaming links and credits. Media and contributors have independent
CRUD endpoints. Release collections use opaque `page[after]` / `page[before]`
cursors and never expose drafts outside the owner's active authorization scope.
