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
