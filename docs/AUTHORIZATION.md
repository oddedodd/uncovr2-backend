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
  belongs. B4 resources will store that scope explicitly.
- **Managing party:** a time-bounded row in
  `organization_artist_relationships`; artists are never owned permanently by
  a label.
- **Explicit editor:** an active `label_user` or `artist_user` membership, and
  later a resource assignment inside that scope.

Laravel Policies combine those facts for every operation. Controllers never
accept a role or owner claim from the client as proof of access.

## Invitations

Organization invitations are email-bound, expire after 72 hours by default,
and are single use. Only a SHA-256 hash is stored in the invitation table. The
queued notification is encrypted, and resending rotates the token and expiry.
Acceptance locks the invitation row before creating the membership, preventing
two concurrent requests from consuming the same invitation.
