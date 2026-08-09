# Authentication architecture

Laravel owns every Uncovr account, credential, session and authorization
decision. Supabase Auth is not part of the account system; Supabase provides the
private PostgreSQL database used by Laravel.

## Client flows

### Registration and required consent

`POST /api/v1/auth/register` requires `display_name`, email, password and a
strict `consents` object. `terms` and `privacy` must be accepted; optional
`marketing_email` and `marketing_push` booleans are recorded independently.
Laravel assigns the configured policy versions, hashes the request IP for the
consent audit trail and never treats marketing consent as permission to suppress
required account or security email.

```json
{
  "display_name": "Ada Listener",
  "email": "ada@example.com",
  "password": "a sufficiently long passphrase",
  "password_confirmation": "a sufficiently long passphrase",
  "consents": {
    "terms": true,
    "privacy": true,
    "marketing_email": false,
    "marketing_push": false
  }
}
```

### Login endpoint

Both clients use `POST /api/v1/auth/login` with normalized email, password,
`client_type` (`portal` or `mobile`) and bounded device metadata:

```json
{
  "email": "artist@example.com",
  "password": "a secure passphrase",
  "client_type": "mobile",
  "device": {
    "name": "Ada's iPhone",
    "platform": "ios",
    "app_version": "1.2.3"
  }
}
```

Only verified accounts may log in. Unknown accounts and incorrect passwords
receive the same `invalid_credentials` response and create no session or token.
The response always includes the user's public ID and the new device session's
public ID; internal database IDs are never exposed. Authentication responses
disable caching.

### React portal

The portal uses Laravel's stateful session cookie with CSRF protection. It never
receives an access token or refresh token in JavaScript, and authentication
secrets must not be stored in local storage. The cookie is HTTP-only, secure in
production and SameSite Lax.

The portal and API must be deployed below the same registrable domain, for
example `admin.uncovr.no` and `api.uncovr.no`. Login regenerates the Laravel
session ID. Portal sessions have a 120-minute idle timeout and a 12-hour
absolute lifetime; there is no remember-me flow in the MVP.

Before portal login, the browser requests `/sanctum/csrf-cookie` and then sends
the login request with credentials from an allowed stateful origin. Successful
login regenerates the Laravel session ID and returns `authentication.type` as
`session`; no access or refresh token is included in JSON.

### Expo application

The mobile app receives a Sanctum bearer access token and an opaque rotating
refresh token. Access tokens are held in memory where practical. Refresh tokens
are stored only in the operating system's secure credential store, such as iOS
Keychain or Android Keystore-backed SecureStore, never AsyncStorage.

Mobile lifetimes are:

- Access token: 15 minutes.
- Refresh-token inactivity window: 30 days, extended on successful rotation.
- Absolute device-session lifetime: 90 days, never extended by rotation.

Successful mobile login returns the plain-text access and refresh credentials
once. Laravel stores only their SHA-256 hashes. The access token has only the
`mobile:access` ability and is linked to the new device session; the refresh
token starts at generation zero. The client must move the refresh token into
the operating system's secure credential store before discarding the response.

The refresh token contains at least 256 bits of cryptographically secure random
entropy and uses the `uncovr_refresh_` prefix. Only its SHA-256 hash is stored.
The access token uses Sanctum's `uncovr_` prefix and is also stored only as a
SHA-256 hash.

## Device sessions

`device_sessions` is the common user-visible session record for portal and
mobile clients. Its public ULID is returned as the session `id`; its internal
BIGINT remains private. It records only the device metadata needed to identify,
secure and revoke a session:

- client type, user-provided or derived device name, platform and app version;
- the linked Laravel web session ID for portal sessions;
- last activity, idle expiry, absolute expiry and revocation state;
- last IP address and user agent for security review.

IP address and user-agent data are security metadata, not device fingerprints.
They must not be used for advertising or shared with labels or artists. Active
session metadata is visible only to the owning user and authorized platform
security staff.

Each mobile device session has at most one usable refresh-token generation and
one current Sanctum access token. `personal_access_tokens.device_session_id`
connects the current access token to its device session. Portal sessions do not
use either token table.

## Refresh rotation

`POST /api/v1/auth/refresh` accepts the refresh token in the JSON body. The field
must be redacted from logs. Mobile clients must serialize refresh attempts so
only one is in flight for a device session.

Laravel performs rotation in one short database transaction:

1. Hash the presented token and resolve its owning device session without
   exposing or logging the token.
2. Lock the device session first and then its `refresh_tokens` row for update;
   every refresh and revocation flow uses this lock order.
3. Validate the device session, refresh-token expiry, idle expiry and absolute
   expiry.
4. Reject a missing, expired or revoked token with the same generic 401 error.
5. If `used_at` is already set, treat it as replay: revoke the whole device
   session, every refresh generation and its Sanctum access token, and record a
   token-free security log event. Persistent audit storage is added in `B2.12`.
6. Mark the current refresh token used, create the next generation, replace the
   Sanctum access token and extend idle expiry up to the absolute session limit.
7. Commit before returning both new plain-text tokens exactly once.

The first concurrent refresh wins. A second request using the old generation is
treated as replay and revokes the session. If a client loses the successful
rotation response, it must log in again; the server never stores a recoverable
plain-text successor token.

Used refresh-token hashes and their replacement links remain until the absolute
session expiry plus 30 days so replay can be detected. A daily scheduled command
then deletes the expired device session and its token generations together.
Sanctum access tokens that have been expired for at least 24 hours are pruned by
a separate daily scheduled command.

Expired or revoked device-session metadata is purged 30 days after its absolute
expiry. Account deletion follows the stricter deletion and backup-retention flow
defined later in the privacy milestone.

## Revocation rules

- Current-device logout revokes the device session, deletes its Laravel session
  or Sanctum access token and revokes all refresh generations atomically.
- Per-session revocation performs the same operation for the selected public
  session ID after ownership authorization.
- Logout-all and password reset revoke every portal and mobile session.
- Password change revokes other sessions and regenerates the current portal
  session; a mobile client must log in again.
- Expiry and refresh-token replay revoke the affected device session.
- Membership or role changes take effect immediately through database-backed
  Laravel Policies; roles are never copied into long-lived tokens.

Revoked credentials always return a generic authentication error. Tokens,
passwords and session IDs are excluded from application logs and audit payloads.

## Account and session endpoints

Authenticated portal sessions and mobile bearer tokens use the same endpoints:

- `POST /api/v1/auth/logout` revokes the current device session.
- `POST /api/v1/auth/logout-all` revokes every portal and mobile session owned
  by the account.
- `GET /api/v1/me` returns the account's public ID, verified email, minimal
  profile and superadmin flag. Keep this response small enough to use directly
  after login.
- `GET /api/v1/me/workspaces` returns the organization/artist workspace list
  with current role and membership status. This is the portal's role-aware
  navigation context, not an authorization contract; Laravel Policies still
  authorize every operation. The backend may briefly cache this response to
  avoid repeated bootstrap graph loads.
- `PATCH /api/v1/me` currently changes only `display_name` and returns the
  minimal account context.
- `GET /api/v1/me/sessions` lists active sessions and identifies the current
  one; `DELETE /api/v1/me/sessions/{public_id}` revokes an owned session.

Every protected request validates the device session's revocation, idle and
absolute expiry state. Portal activity extends only the idle window and never
the absolute lifetime. Session ownership is resolved server-side; a foreign
public session ID returns the same not-found response as an unknown ID.

## Password reset

`POST /api/v1/auth/forgot-password` always returns the same accepted response,
whether or not the normalized email belongs to an account. Laravel stores only
the reset-token hash. The queued notification encrypts the one-time plain token
with `APP_KEY` before its job is persisted, and provides HTML and plain-text
content through Resend.

`POST /api/v1/auth/reset-password` accepts email, token, a password of at least
15 characters and confirmation. A successful reset hashes the new password,
consumes the token and revokes every portal session, mobile access token and
refresh-token generation. Expired, unknown and replayed reset tokens receive
the same generic error.

## Security audit and throttling

Authentication, refresh, logout, session revocation, password reset and profile
changes create append-only `security_audit_events` with public event IDs,
timestamps and bounded request context. Audit metadata rejects password, token
and session-secret field names. The table is in the private `laravel` schema and
has no grants to Supabase public API roles.

Authentication limits combine IP and normalized-identity hashes. Refresh limits
combine IP and a SHA-256 hash of the presented refresh token, so raw credentials
never become rate-limit keys. Error responses do not distinguish unknown users
from known users or expired credentials from revoked credentials.

## Authorization boundary

Protected endpoints use `auth:sanctum`. Token ability `mobile:access` identifies
the first-party mobile client and does not grant domain permissions. Laravel
Policies check current memberships and resource ownership on every protected
operation. The portal's first-party session and mobile token therefore share
the same authorization rules.

## Database and environment

Authentication tables live in the private `laravel` schema and are accessed
only through Laravel's direct PostgreSQL connection. Supabase `anon`,
`authenticated` and `service_role` roles receive no access.

Production configures exact `SANCTUM_STATEFUL_DOMAINS`, CORS origins, session
cookie domain and the documented token lifetimes. Automated tests use isolated
SQLite and never create real browser or mobile sessions in Supabase.

The exact browser settings are:

| Environment | Portal | API | Stateful domain | Session domain |
| --- | --- | --- | --- | --- |
| Local | `http://localhost:5173` | `http://localhost:8000` | `localhost:5173` | `null` |
| Production | `https://admin.uncovr.no` | `https://api.uncovr.no` | `admin.uncovr.no` | `.uncovr.no` |

Production also sets `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`
and `SESSION_SAME_SITE=lax`. Local development uses a non-secure cookie and
must consistently use `localhost` for both processes; `127.0.0.1` is not a
supported portal origin.
