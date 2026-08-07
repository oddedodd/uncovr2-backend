# Authentication architecture

Laravel owns every Uncovr account, credential, session and authorization
decision. Supabase Auth is not part of the account system; Supabase provides the
private PostgreSQL database used by Laravel.

## Client authentication

Laravel Sanctum supports the two first-party client types without exposing
database credentials:

- The Next.js portal uses stateful, HTTP-only Laravel session cookies with CSRF
  protection. The portal and API must be deployed below the same registrable
  domain, for example `app.uncovr.no` and `api.uncovr.no`.
- The Expo application will use short-lived bearer access tokens. The rotating
  refresh-token and device-session design is finalized in `B2.2`; Sanctum's
  default non-expiring token behavior must not be treated as the final design.

Protected endpoints use the `auth:sanctum` middleware. Laravel Policies remain
responsible for resource authorization after Sanctum establishes the identity.
Token abilities may narrow a token's scope but never replace policy checks.

## Storage and secrets

Sanctum stores only SHA-256 token hashes in `laravel.personal_access_tokens`.
Plain-text bearer tokens may be returned once when issued but must never be
stored in the database, logs or analytics. The `uncovr_` token prefix helps
secret-scanning systems identify an accidentally exposed token.

The table is in the private `laravel` schema and is accessed only through
Laravel's direct PostgreSQL connection. Supabase `anon`, `authenticated` and
`service_role` roles receive no access.

## Environment configuration

`SANCTUM_STATEFUL_DOMAINS` is a comma-separated allow-list of portal hosts,
including ports during local development. Production must set its exact portal
hosts. CORS origins and session cookie settings must be configured for the same
deployment topology.

Automated tests use an isolated SQLite database and create no real browser or
mobile sessions in Supabase.
