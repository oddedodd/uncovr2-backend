# Label and artist onboarding API

The portal uses these transactional Laravel endpoints for the guided onboarding
flow. Clients never call Supabase directly. All requests require an authenticated
Laravel session and reject unknown fields.

## 1. Superadmin creates a label and invites its first administrator

`POST /api/v1/platform/organization-onboardings`

```json
{
  "organization": {
    "name": "Northern Sounds",
    "legal_name": "Northern Sounds AS",
    "description": "Independent label.",
    "website_url": "https://northern.example"
  },
  "administrator": {
    "email": "label-admin@example.com"
  },
  "confirmation": true
}
```

Only a superadmin can call this endpoint. Laravel creates the organization and
its profile, creates a single-use `label_admin` invitation and queues the email
after the database transaction commits. If any database operation fails,
nothing is created. The superadmin does not receive an organization membership.

HTTP 201 returns `organization` and `administrator_invitation`. Both contain
public IDs; the invitation also contains normalized email, fixed role
`label_admin` and expiry. No token is returned by the API.

The invited person signs in or registers with the exact invited email address,
then the portal submits the token from its acceptance page to:

`POST /api/v1/organization-invitations/accept`

```json
{ "token": "64-character-token-from-email" }
```

## 2. Label Admin creates an artist and invites its first administrator

`POST /api/v1/organizations/{organization}/artist-onboardings`

```json
{
  "artist": {
    "name": "Midnight Echo",
    "biography": "Electronic duo.",
    "website_url": "https://midnight.example"
  },
  "administrator": {
    "email": "artist-admin@example.com"
  },
  "relationship_type": "managing_label",
  "creator_role": null,
  "confirmation": true
}
```

A Label Admin for the organization, or a superadmin, can call this endpoint.
Laravel creates the artist, profile, active organization relationship and a
single-use `artist_admin` invitation in one transaction. The invitation email is
queued only after commit.

HTTP 201 returns `artist`, `relationship`, `administrator_invitation` and
`creator_membership`. `creator_membership` is `null` unless explicitly selected.
The relationship and every resource use public IDs; no internal database ID or
invitation token is exposed.

`relationship_type` defaults to `managing_label` and may also be `distributor`.
The acting Label Admin receives no artist membership by default. If the product
flow deliberately needs one, `creator_role` must explicitly be `artist_admin`
or `artist_user`.

The legacy standalone `POST /api/v1/artists` contract follows the same rule:
the creator receives no membership unless `creator_role` is explicitly supplied.

The invited person signs in or registers with the exact invited email address,
then the portal's `/artist-invitations/accept` page submits:

`POST /api/v1/artist-invitations/accept`

```json
{ "token": "64-character-token-from-email" }
```

## Additional artist invitations

- `POST /api/v1/artists/{artist}/invitations` with `email` and either
  `artist_admin` or `artist_user` as `role`.
- `POST /api/v1/artist-invitations/{invitation}/resend` rotates the token and
  extends its expiry.

Invitation tokens are stored only as SHA-256 hashes, expire according to
`ARTIST_INVITATION_TTL_HOURS`, are bound to the invited email and can be used
only once. Create, resend, accept and both onboarding operations are audit logged.

## Portal acceptance behavior and errors

The emailed URL opens a portal page. An existing user signs in; a new user
registers and verifies the same invited email first. The authenticated portal
then posts the token to the matching Laravel acceptance endpoint. A different
email receives HTTP 409, while an expired, revoked or already consumed token
receives HTTP 410.

Onboarding and invitation management return HTTP 403 outside the required
superadmin, Label Admin or Artist Admin scope. Invalid or unexpected request
fields return HTTP 422. All routes return HTTP 401 without a valid Laravel
session. The complete machine-readable request contract is in `openapi.json`.
