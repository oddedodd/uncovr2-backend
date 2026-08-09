# Protected portal search API

These authenticated collection endpoints are the backend contract for portal
search. They use the standard response envelope and cursor pagination described
in [API_CONVENTIONS.md](API_CONVENTIONS.md).

## Endpoints

| Endpoint | Search fields | Exact filters | Authorization |
| --- | --- | --- | --- |
| `GET /api/v1/users` | Public ID, email and display name | `status=active\|suspended` | Superadmin only |
| `GET /api/v1/organizations` | Public ID, name and legal name | `status=active\|suspended` | Current organization scope; superadmin sees all |
| `GET /api/v1/artists` | Public ID and artist name | `status=active\|suspended` | Current artist/label scope; superadmin sees all |
| `GET /api/v1/releases` | Public ID, title, subtitle, UPC, artist and owner name | Release `status` and `type=album\|ep\|single` | Current owner scope; superadmin sees all |

All four endpoints accept:

- `filter[search]`: case-insensitive partial search, 2–100 characters.
- `page[size]`: 1–100 records; defaults to 25.
- `page[after]` or `page[before]`: an opaque cursor returned by the API. The
  two cursor directions are mutually exclusive.

Unknown query parameters and filters return HTTP 422. Search never expands the
caller's authorization scope. In particular, non-superadmins cannot discover
suspended or unrelated resources through search terms or exact filters.

The user representation intentionally contains only public identity and account
state fields required by the administration portal. It never returns passwords,
remember tokens, session tokens, internal numeric IDs or relationship foreign
keys.
