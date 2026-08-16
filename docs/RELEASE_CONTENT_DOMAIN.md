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

Ordering is the backend's responsibility. A client states the order it wants and
the API renumbers the whole sibling set to a contiguous `1..n`; it never has to
compute positions for the rows it is not moving. Because the partial unique
indexes are immediate — a partial index cannot be deferred — every renumbering
runs as a two-phase write inside one transaction: `ContentOrderService` parks the
rows above the current maximum position and then writes the final `1..n`.
Deleting a page or block still leaves a gap until the next reorder.

## Portal API contract

All routes are authenticated below `/api/v1`:

| Action | Endpoint | Policy ability |
| --- | --- | --- |
| Create page | `POST /releases/{release}/pages` | `update` release |
| Update page | `PATCH /pages/{page}` | `update` owning release |
| Reorder pages | `PUT /releases/{release}/pages/order` | `update` release |
| Delete page | `DELETE /pages/{page}` | `update` owning release |
| Create block | `POST /pages/{page}/blocks` | `update` owning release |
| Update block | `PATCH /pages/{page}/blocks/{block}` | `update` owning release |
| Reorder blocks | `PUT /pages/{page}/blocks/order` | `update` owning release |
| Delete block | `DELETE /pages/{page}/blocks/{block}` | `update` owning release |
| Block history | `GET /pages/{page}/blocks/{block}/versions` | `view` owning release |
| Assign editor | `POST /releases/{release}/editors` | `manageEditors` release |
| Revoke editor | `DELETE /releases/{release}/editors/{user}` | `manageEditors` release |

Laravel `ReleasePolicy` is the authorization boundary. A nested block route
also verifies that the block belongs to the page in the URL. Hiding controls in
the portal is never treated as authorization.

## Editors and capability flags

Every scope member may read a draft, but only scope administrators and
explicitly assigned release editors may write to it. Assignment is per release:
`POST /releases/{release}/editors` takes the target user's ULID, is idempotent
(`201` on a new grant, `200` when the assignment already exists), and only a new
grant emails the assigned user. Creating a release assigns its creator.

The release detail and summary payloads carry both the assignment list and the
requesting user's capabilities:

```json
{
  "editors": [{ "user_id": "01USER_ULID", "display_name": "Ada Artist" }],
  "permissions": {
    "can_update": true, "can_submit": true, "can_delete": true,
    "can_approve": false, "can_publish": false, "can_manage_editors": false
  }
}
```

`editor_user_ids` remains in both payloads for compatibility and is
**deprecated** in favour of `editors`. Editor email addresses are deliberately
absent: the summary is readable by every scope member, while emails are exposed
only behind `manageMembers` on `GET /artists/{artist}/members`.

`GET /releases?filter[assigned_to_me]=1` narrows the listing to explicit
assignments. It means exactly that — an administrator who was never assigned
gets an empty list even though they may edit every release in the scope.

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

## Ordering contract

`PUT /releases/{release}/pages/order` and `PUT /pages/{page}/blocks/order` take
the complete sibling set in the wanted order:

```json
{ "page_ids": ["01PAGE_C", "01PAGE_A", "01PAGE_B"] }
```

Both return the reordered siblings, renumbered `1..n`, in an array:

```json
{
  "data": [
    { "id": "01PAGE_C", "parent": { "type": "release", "id": "01RELEASE_ULID" },
      "position": 1, "title": "Back cover", "blocks": [] }
  ]
}
```

The list must be a complete permutation of the parent's current children. A
partial list, an unknown id, a duplicate or an id belonging to another parent is
rejected with `422 validation_failed` on `page_ids` / `block_ids` — a reorder is
never applied halfway. Reordering blocks is pure ordering: it does not bump
`version` and writes no `content_block_versions` row.

`PATCH` still accepts `position` on a page and a block, and it now means "move
here", not "claim this slot": the row moves to that position, the remaining
siblings close the gap, and the whole set is renumbered `1..n`. A position beyond
the sibling count is clamped to last. `POST` is unchanged — creating a child on a
position another child already holds is still `422`, so a create form should
default to `n + 1`.

Activity history records `page.reordered` and `content_block.reordered` with the
resulting id order, rather than one update event per moved row.

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
