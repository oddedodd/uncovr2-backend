# Identifier strategy

Uncovr separates database identity from public API identity.

## Internal identifiers

Domain tables use an auto-incrementing PostgreSQL `BIGINT` primary key named
`id`. Foreign keys and join tables use these internal keys. They keep indexes
and joins compact and are never accepted from or returned to an API client.

## Public identifiers

Every addressable domain resource also has an immutable, unique `public_id`
column containing a lowercase ULID. In Laravel migrations, use:

```php
$table->id();
$table->ulid('public_id')->unique();
```

Models use `App\Models\Concerns\HasPublicId` to generate the ULID, hide the
internal primary key during serialization and bind routes through `public_id`.
API resources expose the value as `id`, not `public_id`:

```json
{
  "id": "01k00000000000000000000000"
}
```

All API route parameters and resource references use public IDs. A numeric
database ID supplied where a public ID is required is treated as not found.
Authorization must still be checked after lookup; an unguessable identifier is
not an access-control mechanism.

## Rules and exceptions

- Public IDs are generated once and never changed or reused.
- Database uniqueness is the final collision guarantee.
- Ordering uses an explicit column such as `created_at`, with `public_id` as a
  deterministic tie-breaker. Code must not rely on ULID ordering alone.
- ULIDs contain an approximate creation timestamp. They must not encode any
  other business or personal information.
- Non-addressable pivot and audit-detail rows need only internal IDs.
- Stable vocabulary values such as statuses and roles use documented string
  codes instead of resource IDs.
- External identifiers such as ISRC, UPC and provider IDs live in explicit
  fields and never replace Uncovr's own public ID.
