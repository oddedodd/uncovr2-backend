# Release pages and block-editor domain

The portal models a release as a digital edition of a physical record cover,
booklet or insert. It is not an album object containing a track list. The
`album`, `ep` and `single` values are release metadata classifications only;
they do not change the content tree.

## Portal builder aggregate

The editable portal tree is:

```text
release
├── artists
├── pages
│   └── blocks
├── streaming links
└── credits
```

Pages are ordered directly within the release. Blocks are ordered within their
page and may be freely combined to build the booklet. `ReleaseResource` returns
release-level `pages`, and every page contains its ordered `blocks`. It does not
return a `tracks` member.

Positions are positive and unique within their parent. Database partial unique
indexes ignore soft-deleted rows so a position can be reused without destroying
its historical record.

## Portal API contract

All routes are authenticated below `/api/v1`:

| Action | Endpoint | Policy ability |
| --- | --- | --- |
| Create page | `POST /releases/{release}/pages` | `update` release |
| Update page | `PATCH /pages/{page}` | `update` owning release |
| Delete page | `DELETE /pages/{page}` | `update` owning release |
| Create block | `POST /pages/{page}/blocks` | `update` owning release |
| Update block | `PATCH /pages/{page}/blocks/{block}` | `update` owning release |
| Delete block | `DELETE /pages/{page}/blocks/{block}` | `update` owning release |
| Block history | `GET /pages/{page}/blocks/{block}/versions` | `view` owning release |

Laravel `ReleasePolicy` is the authorization boundary. A nested block route
also verifies that the block belongs to the page in the URL. Hiding controls in
the portal is never treated as authorization.

Page create payload and response:

```json
{
  "position": 1,
  "title": "Front cover"
}
```

```json
{
  "data": {
    "id": "01PAGE_ULID",
    "parent": { "type": "release", "id": "01RELEASE_ULID" },
    "position": 1,
    "title": "Front cover",
    "blocks": []
  }
}
```

Block create payload and response:

```json
{
  "position": 1,
  "type": "heading",
  "payload": { "text": "The story", "level": 1 }
}
```

```json
{
  "data": {
    "id": "01BLOCK_ULID",
    "page_id": "01PAGE_ULID",
    "position": 1,
    "type": "heading",
    "version": 1,
    "payload": { "text": "The story", "level": 1 }
  }
}
```

PATCH requests accept the same mutable fields and may send only the fields that
change. Existing soft deletion, immutable block versions and release activity
history remain in use.

The relevant `ReleaseResource` shape for P5.4 is:

```json
{
  "data": {
    "id": "01RELEASE_ULID",
    "title": "Signals",
    "pages": [
      {
        "id": "01PAGE_ULID",
        "parent": { "type": "release", "id": "01RELEASE_ULID" },
        "position": 1,
        "title": "Front cover",
        "blocks": [
          {
            "id": "01BLOCK_ULID",
            "page_id": "01PAGE_ULID",
            "position": 1,
            "type": "heading",
            "version": 1,
            "payload": { "text": "The story", "level": 1 }
          }
        ]
      }
    ]
  }
}
```

The resource contains the existing release metadata, artists, cover, links,
credits and lifecycle fields in addition to this excerpt. It intentionally has
no `tracks` field.

## Content blocks and history

Each block stores a validated JSONB payload and an incrementing version. Every
create and update writes an immutable row to `content_block_versions`.

- `heading`: text and heading level 1–6.
- `text`: body text.
- `image`: same-scope media ID, alt text and optional caption.
- `gallery`: 1–50 distinct same-scope media items with alt text.
- `video`: exactly one HTTPS URL or same-scope media ID.
- `quote`: text and optional attribution.
- `lyrics`: text and optional language code. This is a free-form design block,
  not a track or track-page relationship.

Unknown payload fields and cross-scope media references are rejected. Media
continues to use the verified flow in `MEDIA_AND_PUBLISHING.md`.

## Legacy track compatibility

Track tables and routes remain because the existing public catalog, track
favorites, collections and already published snapshots depend on them. They are
not part of the portal release-builder contract:

- Portal code must not call track CRUD, track-page, track-credit or
  track-streaming-link routes.
- OpenAPI marks track mutations as deprecated and labels all track routes with
  `x-uncovr-contract: legacy-track-compatibility`.
- New portal release resources and approval previews are page/block based.
- Publication temporarily preserves legacy track snapshots so existing listener
  features and published links do not break.
- `pages.track_id` remains only for legacy rows; portal-created pages always use
  `pages.release_id`.

Removal must be a separate, audited migration rather than implicit cleanup:

1. inventory track, track-page, track-credit, favorite, collection and
   publication rows in production;
2. decide whether listener track favorites and deep links are retired or mapped
   to release/page concepts;
3. migrate or archive dependent data and support old published URLs for an
   agreed compatibility period;
4. stop legacy writes, then remove routes, snapshots and tables in a versioned
   breaking migration.
