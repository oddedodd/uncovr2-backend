# Uncovr API conventions

All public endpoints are versioned below `/api/v1` and return JSON.

## Successful responses

Successful responses wrap their result in `data`:

```json
{
  "data": {
    "id": "example"
  }
}
```

List responses may include an optional `meta` object for pagination and other
response-level information:

```json
{
  "data": [],
  "meta": {
    "pagination": {
      "per_page": 25,
      "next_cursor": null,
      "previous_cursor": null,
      "has_more": false
    }
  }
}
```

## Error responses

Errors wrap a stable machine-readable code and a safe human-readable message
in `error`:

```json
{
  "error": {
    "code": "not_found",
    "message": "The requested resource was not found."
  }
}
```

## Resource and field naming

- URLs use lowercase plural nouns: `/artists`, `/releases` and
  `/organizations/{organization}/members`.
- Multi-word URL segments use kebab-case. JSON fields and query parameter names
  use snake_case.
- Route placeholders use singular resource names and resolve public ULIDs, for
  example `/artists/{artist}`. See [IDENTIFIERS.md](IDENTIFIERS.md).
- Base resource paths do not contain verbs. A genuine command uses a
  subordinate action, such as `POST /releases/{release}/publish`.
- Relationships use the related resource's public ID in an `_id` field. Arrays
  of references use `_ids` only when the order has no domain meaning.
- Boolean fields start with `is_`, `has_` or `can_` when that makes their
  meaning clearer.
- Unknown request fields are rejected by validation for write operations.

Standard resource operations are `GET` for reads, `POST` for creation or
commands, `PATCH` for partial updates and `DELETE` for deletion. `PUT` is
reserved for true full replacement. Creation normally returns HTTP 201;
asynchronous work returns 202; successful operations with no response document
may return 204.

## Cursor pagination

Growing collections use cursor pagination rather than numbered pages. The
request parameters are:

- `page[size]`: number of records, default 25 and maximum 100.
- `page[after]`: opaque cursor for the next page.
- `page[before]`: opaque cursor for the previous page.

`page[after]` and `page[before]` are mutually exclusive. Cursors are opaque and
must be returned unchanged by clients. Responses put pagination state below
`meta.pagination` as shown in the success example. Total counts are omitted by
default because they may be expensive and immediately stale; endpoints that
need a total must document it explicitly.

Every paginated query uses deterministic ordering. The final tie-breaker is the
resource's `public_id`, exposed as `id` in the API.

## Filtering and search

Filters use bracket notation and are explicitly allow-listed per endpoint:

```text
GET /api/v1/releases?filter[status]=draft&filter[artist_id]=01k...
```

Exact matching is the default. Free-text search uses `filter[search]`. Range
filters use clear suffixes such as `filter[created_from]` and
`filter[created_until]`. An endpoint must explicitly document whether a filter
accepts multiple comma-separated values. Unknown filters, invalid public IDs
and invalid values return HTTP 422 instead of being silently ignored.

## Sorting

Sorting uses a comma-separated `sort` parameter. A leading `-` means descending:

```text
GET /api/v1/releases?sort=-created_at,title
```

Each endpoint allow-lists sortable fields and documents its default. Unknown or
unsupported sort fields return HTTP 422. Collection queries add `id` as a final
deterministic tie-breaker when it is not already present. Null ordering must be
defined by an endpoint whenever a sortable field is nullable.

## Timestamps and dates

- Database timestamps use timezone-aware PostgreSQL columns.
- API timestamps are UTC RFC 3339 strings with millisecond precision, for
  example `2026-08-07T10:15:30.123Z`.
- Client-supplied timestamps must include `Z` or an explicit numeric offset.
- Calendar-only values use `YYYY-MM-DD`; durations use integer milliseconds.
- Standard lifecycle names are `created_at`, `updated_at`, `published_at` and,
  where applicable, `deleted_at`.
- A known empty value is returned as `null`. A field is omitted only when it is
  unavailable in the current representation or excluded intentionally.
- Clients must not derive ordering or authorization decisions from timestamps.

Callers must use `error.code` for application decisions. Error messages may be
shown to users but must not be parsed or used as identifiers. Internal
exception messages, stack traces and credentials are never returned.

## Validation errors

Validation failures use HTTP 422 and include errors grouped by input field:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The submitted data is invalid.",
    "details": {
      "fields": {
        "email": [
          "The email field is required."
        ]
      }
    }
  }
}
```

## Request correlation and logs

Every API response includes an `X-Request-ID` header containing a UUID. Clients
and trusted proxies may provide their own UUID in the same request header;
invalid values are replaced. Include this ID when reporting an API problem.

Application logs are JSON and attach `request_id` to every entry written while
an API request is handled. One completion event is logged with the HTTP method,
path, named route, status code and duration in milliseconds. Query strings and
request bodies are not included in completion logs.

## Operational health

- `GET /api/v1/health/live` confirms that the Laravel process can answer HTTP
  requests. It does not query external dependencies.
- `GET /api/v1/health/ready` confirms that the application can execute a
  minimal query through its configured database connection.

Both endpoints disable response caching. Readiness failures return HTTP 503
with `service_unavailable`; database errors and connection details are logged
internally but never returned to the caller.

## Browser origins and transport security

Browser access is credentialed and restricted to the comma-separated origins
in `CORS_ALLOWED_ORIGINS`. Values must include the scheme and port when
applicable. Wildcard origins are not supported. `X-Request-ID` is exposed so
the portal can include it in support and error reports.

Production must use HTTPS, set `APP_URL` to the canonical backend URL and set
`TRUSTED_HOSTS` to a comma-separated list of exact hostnames. Session cookies
default to `Secure`, `HttpOnly` and `SameSite=Lax` in production. API responses
also deny framing, MIME sniffing and browser permissions; HTTPS production
responses include HSTS.

## Rate-limit groups

- `public` limits unauthenticated product endpoints per IP address.
- `authenticated` limits protected endpoints per user identifier, with the IP
  address as a fallback before authentication has completed.
- `authentication` applies both an IP limit and a stricter normalized-identity
  plus IP limit to login, registration and account-recovery endpoints. The
  normalized identity is SHA-256 hashed before it becomes part of a cache key.

Limits are configured with the `RATE_LIMIT_*` environment variables. A blocked
request returns HTTP 429 using the standard API error envelope and includes the
normal rate-limit and retry headers. Operational health checks are intentionally
outside these application groups and should be protected at the infrastructure
layer instead.

## Feature-test foundation

Feature tests extend `Tests\TestCase`, which applies `RefreshDatabase` and the
`InteractsWithApi` helper trait. PHPUnit forces SQLite `:memory:` and clears
`DB_URL`, so automated tests never connect to Supabase. Helpers such as
`getApi`, `postApi`, `assertApiSuccess` and `assertApiError` keep version
prefixes, JSON headers and envelope assertions consistent.
