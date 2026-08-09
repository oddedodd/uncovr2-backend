# Platform administration API

These Laravel endpoints support the superadministrator portal. They use the
normal Uncovr authentication middleware and never expose Supabase clients,
credentials, table names or provider-specific response formats.

## Overview and user hierarchy

- `GET /api/v1/platform/overview` returns totals and status breakdowns for
  users, organizations, artists and non-deleted releases.
- `GET /api/v1/users/{user}` returns the public account representation,
  organization and artist memberships, their resource hierarchy, and release
  editor assignments.

Both endpoints are superadmin-only. User hierarchy responses contain public
ULIDs and portal-safe fields, never numeric database keys or credentials.

## Account suspension and restoration

`PATCH /api/v1/users/{user}/status` accepts:

```json
{
  "status": "suspended",
  "reason": "Confirmed reason of at least ten characters.",
  "confirmation": "01PUBLIC_USER_ULID"
}
```

`confirmation` must exactly equal the target user's public ID. Suspension:

- cannot be performed on the acting superadmin's own account;
- cannot leave the platform without an active superadmin;
- revokes device sessions, refresh tokens, access tokens and database-backed
  browser sessions;
- blocks new login, refresh and protected API access; and
- records actor, target, transition, reason and revoked-session count in the
  security audit log.

Restoration uses the same endpoint with `status=active`. It is audited but does
not restore revoked sessions; the user must authenticate again.

## Role correction

- `PATCH /api/v1/users/{user}/organization-memberships/{membership}/role`
- `PATCH /api/v1/users/{user}/artist-memberships/{membership}/role`

Both accept `role`, `reason` and `confirmation`. Confirmation must equal the
target user's public ID, and the membership must belong to that user. Existing
last-active-administrator protection remains in force. The audit event stores
the actor, target, scope, membership, previous and new role, and reason.

All mutation endpoints reject unknown fields and return HTTP 422 for invalid
confirmation, role or reason. Authenticated non-superadmins receive HTTP 403.
