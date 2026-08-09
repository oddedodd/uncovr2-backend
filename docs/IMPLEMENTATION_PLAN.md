# Uncovr backend implementation plan

Last updated: 2026-08-09

This plan owns Laravel API, database, storage, email and backend operations work.
The [cross-repository roadmap](https://github.com/oddedodd/Uncovr2-implentation-docs/blob/main/IMPLEMENTATION_PLAN.md)
is maintained in the workspace meta-repository, while
[portal work](https://github.com/oddedodd/uncovr2-portal/blob/main/IMPLEMENTATION_PLAN.md)
is maintained in the portal repository.

## How we use this plan

- `[x]` means implemented and verified.
- `[ ]` means not completed.
- Work starts at the first unchecked item in the active milestone.
- Checkboxes are updated only after relevant tests and acceptance criteria pass.
- Task IDs remain stable so they can be referenced in issues, commits and prompts.
- The backend-to-portal gate after B0 through B6 must pass before dependent
  portal feature work begins.
- B7 begins only after the portal phase gate and immediately before Expo work.

## Backend architecture

- Laravel owns authentication, authorization, validation and domain logic.
- Supabase Auth is not used for Uncovr accounts.
- Supabase hosts PostgreSQL and, later, object storage.
- Resend is the transactional email provider, used through Laravel Mail and
  Notifications so domain code is not coupled directly to the provider SDK.
- Laravel tables live in the private `laravel` schema.
- Clients never connect directly to Laravel's database tables.
- Production exposes Laravel at `https://api.uncovr.no`; local development uses
  `http://localhost:8000`.
- The admin portal authenticates through Laravel Sanctum's stateful session and
  CSRF flow. Mobile clients use the documented rotating-token flow.
- One user account may be a listener and hold memberships in several labels or artists.
- Roles grant scope; Laravel Policies enforce access on every protected operation.
- Labels and artists receive aggregate listener insights, never individual private collections.

---

# B — Laravel backend

## B0 — Repository and database foundation

- [x] `B0.1` Install Laravel 13 and verify the application starts.
- [x] `B0.2` Initialize Git on the `main` branch.
- [x] `B0.3` Protect secrets, dependencies, caches and generated files with `.gitignore`.
- [x] `B0.4` Connect Laravel to the active Supabase project `Uncovr-db`.
- [x] `B0.5` Create and configure the private `laravel` database schema.
- [x] `B0.6` Require SSL for the PostgreSQL connection.
- [x] `B0.7` Run and verify Laravel's base migrations.
- [x] `B0.8` Confirm `anon`, `authenticated` and `service_role` cannot access the private schema.
- [x] `B0.9` Replace the framework README with Uncovr project documentation.
- [x] `B0.10` Create the intentional initial Git commit.
- [x] `B0.11` Add CI for tests, formatting and static checks.

### B0 gate

- [x] A fresh checkout can be configured from documented instructions.
- [x] Tests pass locally and in CI.
- [x] No secret or local database file is tracked by Git.

## B1 — API conventions and application skeleton

- [x] `B1.1` Add `routes/api.php` and version all endpoints below `/api/v1`.
- [x] `B1.2` Define a consistent JSON success, validation and error format.
- [x] `B1.3` Add request IDs and structured application logging.
- [x] `B1.4` Add health and readiness endpoints without sensitive details.
- [x] `B1.5` Configure trusted origins, CORS and secure production defaults.
- [x] `B1.6` Define rate-limit groups for public, authenticated and authentication routes.
- [x] `B1.7` Decide and document internal and public identifier strategy.
- [x] `B1.8` Establish feature-test helpers for JSON APIs and database isolation.
- [x] `B1.9` Document API naming, pagination, filtering, sorting and timestamps.

### B1 gate

- [x] Versioned API routes respond with the agreed format.
- [x] Health checks, rate limits and error handling have automated tests.

## B2 — Accounts, authentication and device sessions

- [x] `B2.1` Install and configure Laravel Sanctum for first-party authentication.
- [x] `B2.2` Finalize the access-token and rotating refresh-token design.
- [x] `B2.3` Extend users and profiles with required, minimal personal data.
- [x] `B2.4` Implement `POST /api/v1/auth/register`.
- [x] `B2.5` Configure Resend and implement the email-verification and resend flow.
  - [x] `B2.5a` Install the supported Resend transport, validate required environment
    variables and keep API keys and webhook secrets outside Git.
  - [x] `B2.5b` Configure a dedicated sending subdomain and document SPF, DKIM,
    DMARC, sender and reply-to requirements for each environment.
  - [x] `B2.5c` Create a shared, accessible Uncovr transactional-email layout with
    HTML and plain-text versions, safe URLs and local previews.
  - [x] `B2.5d` Queue transactional mail after database commit with controlled
    retries, failure visibility and deterministic Resend idempotency keys.
  - [x] `B2.5e` Make verification links signed, expiring and single-use; throttle
    resends and return enumeration-safe API responses.
- [x] `B2.6` Implement `POST /api/v1/auth/login` with device information.
- [x] `B2.7` Implement refresh-token rotation and reuse detection.
- [x] `B2.8` Implement current-device logout and logout from all devices.
- [x] `B2.9` Implement forgotten-password and password-reset flows with queued,
  expiring and single-use Resend notifications.
- [x] `B2.10` Implement `GET/PATCH /api/v1/me`.
- [x] `B2.11` Implement session listing and per-session revocation.
- [x] `B2.12` Add authentication throttling, audit events and safe error messages.
- [x] `B2.13` Test registration, verification, login, refresh, revocation and reset
  end to end, including mail recipients, queued notifications, rendered content,
  link expiry, replay rejection and resend throttling.
- [x] `B2.14` Add an explicit staging smoke test for real Resend delivery to a
  controlled address; automated tests and CI must always fake mail and perform
  no external sends.
  - [x] The guarded smoke-test command sent one HTML and plain-text message to
    `post@odde.org` and recorded Resend ID
    `0dd0fa7f-a622-41c8-83fa-a81acfa721dd` on 2026-08-08.
  - [x] Inbox placement was confirmed by the owner of `post@odde.org` on
    2026-08-08. The scoped sending key cannot read delivery events; SPF, DKIM
    and DMARC were verified through the configured Resend domain and DNS checks.

### B2 gate

- [x] A listener can register, verify, log in, refresh and log out.
- [x] A revoked or reused token cannot access protected endpoints.
- [x] Passwords and tokens are never stored in plaintext.
- [x] Verification and password-reset emails render correctly, arrive through
  Resend in staging and cannot be replayed after use or expiry.
- [x] Automated tests never contact Resend or send real email.

## B3 — Organizations, artists, roles and authorization

- [x] `B3.1` Create organizations and organization profiles.
- [x] `B3.2` Create organization memberships with `label_admin` and `label_user` roles.
- [x] `B3.3` Create organization invitations with expiry, single-use acceptance
  and a queued Resend invitation notification.
- [x] `B3.4` Create artists and artist profiles.
- [x] `B3.5` Create artist memberships with `artist_admin` and `artist_user` roles.
- [x] `B3.6` Create organization-to-artist relationships without hard-coding permanent ownership.
- [x] `B3.7` Implement platform-level superadmin authorization.
- [x] `B3.8` Add creator, owner, managing party and explicit editor concepts to editable resources.
- [x] `B3.9` Implement Laravel Policies for organizations, artists and memberships.
- [x] `B3.10` Ensure access can be granted at multiple scopes to the same user.
- [x] `B3.11` Log invitations, role changes, suspensions and membership removal.
- [x] `B3.12` Add a complete authorization matrix as automated feature tests.
- [x] `B3.13` Test invitation email recipients, content, authorization, expiry,
  resend behavior and replay protection without making external mail requests.
- [x] `B3.14` Add transactional label and artist onboarding commands with first-
  administrator invitations, explicit-only artist creator roles and rollback
  coverage for failed invitation creation.

### B3 gate

- [x] Superadmin can see and manage the whole platform.
- [x] Label Admin can manage only its label, artists and team.
- [x] Artist Admin can manage only its artist and team.
- [x] Superadmin can onboard a label without receiving label membership, and a
  Label Admin can onboard a related artist without receiving an implicit artist role.
- [x] Label User and Artist User can change only owned or assigned resources.
- [x] Removing membership immediately removes its access.

## B4 — Release and content domain

- [x] `B4.1` Create releases with album, EP and single types.
- [x] `B4.2` Create release-to-artist relationships with a primary artist.
- [x] `B4.3` Create ordered tracks.
- [x] `B4.4` Create ordered pages belonging to releases or tracks.
- [x] `B4.5` Create versioned, validated content blocks.
- [x] `B4.6` Support initial block types: heading, text, image, gallery, video, quote and lyrics.
- [x] `B4.7` Create streaming links with validated service and URL fields.
- [x] `B4.8` Create structured credits and contributor information.
- [x] `B4.9` Create media records independently of storage implementation.
- [x] `B4.10` Preserve creator, owner, editors and modification history.
- [x] `B4.11` Implement CRUD endpoints protected by B3 policies.
- [x] `B4.12` Test ordering, ownership, assignments and cross-tenant isolation.

### B4 gate

- [x] An authorized artist or label user can create a complete draft release.
- [x] The draft can contain tracks, pages, blocks, links, media references and credits.
- [x] Users outside the owning scope cannot read drafts or modify content.

## B5 — Media, approval and publishing

- [x] `B5.1` Define private and public Supabase Storage buckets and retention rules.
- [x] `B5.2` Implement authorized upload requests without exposing server secrets.
- [x] `B5.3` Validate MIME type, size, ownership and image metadata.
- [x] `B5.4` Implement safe replacement and deletion of media.
- [x] `B5.5` Add release states: draft, review, scheduled, published, unpublished and archived.
- [x] `B5.6` Implement approval requests and decisions.
- [x] `B5.7` Implement permission-controlled publishing and unpublishing.
- [x] `B5.8` Implement scheduled publication through queues.
- [x] `B5.9` Produce immutable activity records for sensitive publication actions.
- [x] `B5.10` Test upload authorization, approval transitions and publishing rules.
- [x] `B5.11` Reuse verified Media records for label logos, artist logos,
  artist images and release covers with ready/type/owner validation and safe
  nullable profile references.
- [x] `B5.12` Protect referenced profile and cover media from deletion and add
  an authorized batch contract for short-lived private image URLs.

### B5 gate

- [x] A draft can be previewed, submitted, approved and published.
- [x] Only authorized roles can approve or publish.
- [x] Published content remains separate from private drafts.
- [x] Profile images and covers use the existing verified upload flow, expose no
  Storage credentials or permanent private URL, and can be detached without deletion.

## B6 — Public content API and discovery foundation

- [x] `B6.1` Implement public label, artist, release and track representations.
- [x] `B6.2` Return only published and currently available content.
- [x] `B6.3` Add paginated search for labels, artists and releases.
- [x] `B6.4` Add featured and recent-release endpoints.
- [x] `B6.5` Add cache headers and safe server-side caching.
- [x] `B6.6` Prevent drafts, internal notes and private membership data from leaking.
- [x] `B6.7` Add contract tests for all public representations.

## Backend-to-portal gate

- [x] Superadmin can atomically create a label, invite its first Label Admin and
  inspect the hierarchy without receiving ordinary label membership.
- [x] Label Admin can atomically create a related artist, invite its first
  Artist Admin and manage team members without receiving an implicit artist role.
- [x] Artist Admin can manage its team and create a release.
- [x] A release can progress from draft to published.
- [x] Unauthorized and cross-tenant API operations are rejected and tested.
- [x] Portal-required API contracts and local Sanctum behavior are documented and stable.

## B7 — Listener domain, privacy and notifications

This work was explicitly advanced after the portal foundation was completed.
The complete portal-to-mobile gate still remains required before Expo work.

- [x] `B7.1` Implement unique artist follows.
- [x] `B7.2` Implement unique release and track favorites.
- [x] `B7.3` Implement private collections and ordered collection items.
- [x] `B7.4` Implement notification preferences by channel and topic, keeping
  required account/security email separate from optional marketing consent.
- [x] `B7.5` Register devices and deactivate push tokens on logout or deletion.
- [x] `B7.6` Create an in-app notification model and paginated endpoint.
- [x] `B7.7` Implement user data export.
- [x] `B7.8` Implement documented account deletion and retention workflow.
- [x] `B7.9` Record required consent without mixing operational and marketing messages.
- [x] `B7.10` Expose only aggregate follow and favorite statistics to labels and artists.
- [x] `B7.11` Test that one listener can never read another listener's private data.

## B8 — Operations and backend release readiness

- [x] `B8.1` Configure production queues, retries and failed-job handling.
- [x] `B8.2` Make Resend transactional email production-ready.
  - [x] `B8.2a` Configure scoped production credentials, verified sending
    subdomain, SPF, DKIM, DMARC, sender identities and secret rotation.
  - [x] `B8.2b` Receive Resend delivery webhooks over HTTPS and verify the raw
    request signature before processing any event.
  - [x] `B8.2c` Make webhook processing idempotent using `svix-id` and tolerate
    duplicate and out-of-order delivery.
  - [x] `B8.2d` Store only necessary provider message IDs and delivery state;
    handle delivered, bounced, complained, suppressed and failed outcomes.
  - [x] `B8.2e` Add metrics and alerts for queue failures, provider errors and
    abnormal bounce or complaint rates without logging email bodies or secrets.
  - [x] `B8.2f` Test valid, invalid, replayed, duplicate and out-of-order webhooks,
    then run a documented production-like delivery and bounce smoke test.
- [x] `B8.3` Add error monitoring and production-safe logging.
- [x] `B8.4` Document backup, restore and incident procedures.
- [x] `B8.5` Add database security and performance checks to release procedure.
- [x] `B8.6` Publish machine-readable API documentation.
- [x] `B8.7` Seed a deterministic demo label, users, artists and release.
- [x] `B8.8` Run the complete test suite against a production-like environment.
- [x] `B8.9` Add protected, scope-aware and cursor-paginated portal search for
  users, organizations, artists and releases.
- [x] `B8.10` Add superadmin platform overview, user hierarchy, account
  suspension and audited role-correction contracts required by portal P2.
- [x] `B8.11` Add transactional label and artist onboarding contracts required
  by portal P3, including first-administrator invitations, artist acceptance,
  label relationships and explicit-only creator artist roles.

## Backend release-readiness gate

- [x] The backend-to-portal gate remains satisfied.
- [x] Listener-domain privacy, synchronization and notification behavior in B7 is tested.
- [x] Database, queue, email, storage and monitoring integrations are documented.
- [x] Resend domain authentication, queued sending, idempotency, signed webhooks,
  bounce handling and alerting have passed a production-like verification.
- [x] Label and artist onboarding are transactional, invitation acceptance is
  email-bound and single-use, and implicit artist-role assignment is disabled.
