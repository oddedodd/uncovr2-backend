# Uncovr authorization model

Laravel is the sole authorization authority. Supabase provides the private
PostgreSQL database, but Supabase Auth and Data API roles are not used.

## Scopes and roles

- `is_superadmin` is a platform-level capability and bypasses domain policies.
- Organization memberships are independent per organization. `label_admin`
  manages the organization, its team and actively related artists;
  `label_user` can see the scope and may edit only resources explicitly
  assigned to that user.
- Artist memberships are independent per artist. `artist_admin` manages the
  artist and its team; `artist_user` is an explicit editor for that artist and
  its assigned resources.
- A user may hold any combination of organization and artist memberships.
  Permissions are accumulated, never replaced by another membership.

Every authorization query requires an active resource, an active membership
and, for organization-derived artist access, an active relationship. Removing
or suspending a membership therefore removes access immediately.

## Editable-resource concepts

The domain keeps these concepts separate:

- **Creator:** immutable attribution through `created_by_user_id`; it does not
  grant permanent access by itself.
- **Owner scope:** the organization or artist to which an editable resource
  belongs. Releases, media and contributors store exactly one such scope.
- **Managing party:** a time-bounded row in
  `organization_artist_relationships`; artists are never owned permanently by
  a label.
- **Explicit editor:** an active `label_user` or `artist_user` membership, and
  a `release_editor` assignment inside that scope. Creating a release creates
  this assignment for the creator, while scope administrators may grant or
  revoke assignments for other active scope members.

Laravel Policies combine those facts for every operation. Controllers never
accept a role or owner claim from the client as proof of access.

## Invitations

Organization and artist invitations are email-bound, expire after 72 hours by
default, and are single use. Only a SHA-256 hash is stored in the invitation
table. Queued notifications are encrypted and dispatched only after the
surrounding transaction commits; resending rotates the token and expiry.
Acceptance locks the invitation and parent scope before creating the membership,
preventing two concurrent requests from consuming the same invitation.

Superadmin label onboarding creates the organization, profile and first
`label_admin` invitation atomically without granting the superadmin ordinary
label membership. Label Admin artist onboarding creates the artist, profile,
active label relationship and first `artist_admin` invitation atomically. The
Label Admin receives no artist membership unless `creator_role` is explicitly
supplied. Creator attribution alone never grants access.

## Draft releases

Everyone with an active membership in the owner scope may read its drafts.
Only scope administrators and explicitly assigned release editors may modify
them. Creator attribution alone never bypasses a revoked membership. An
artist-owned release also follows active organization-to-artist relationships,
so ending the managing relationship removes the label's derived access.

Scope administrators grant and revoke those assignments through
`POST`/`DELETE /releases/{release}/editors`. A new grant emails the assigned
user; revocation is recorded in the release activity log and sends nothing.

## Capability flags

Release payloads include a `permissions` block so the portal never has to
reimplement the policy. Each flag mirrors the matching `Gate` ability for the
requesting user, superadmins included, and `ReleaseAuthorizationTest` asserts
that equivalence for every ability and release status.

The flags express **role capability, not state-machine validity**: `can_submit`
means the user is allowed to submit, never that the release is complete enough
to pass validation. Services still reject illegal transitions, so a client must
handle a rejection even when the flag is true. As everywhere else, hiding a
control in the portal is a usability choice and never authorization.
