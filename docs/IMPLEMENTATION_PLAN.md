# Uncovr 2.0 implementation plan

Last updated: 2026-08-07

This roadmap translates the Uncovr 2.0 product plan into an ordered delivery
sequence for Codex. It is the operational plan; the full product plan remains
the source for product intent, roles, privacy requirements and MVP scope.

## How we use this plan

- `[x]` means implemented and verified.
- `[ ]` means not completed.
- Work starts at the first unchecked item in the active milestone.
- Checkboxes are updated only after relevant tests and acceptance criteria pass.
- Every task has a stable ID, such as `B2.4`, which can be referenced in a Codex prompt.
- We complete each phase gate before starting the next product surface.
- Expo work does not begin until the Laravel API and Next.js portal pass their gates.

## Fixed architecture decisions

- Laravel owns authentication, authorization, validation and domain logic.
- Supabase Auth is not used for Uncovr accounts.
- Supabase hosts PostgreSQL and, later, object storage.
- Resend is the transactional email provider, used through Laravel Mail and
  Notifications so domain code is not coupled directly to the provider SDK.
- Laravel tables live in the private `laravel` schema.
- Clients never connect directly to Laravel's database tables.
- The Next.js portal is the working interface for superadmins, labels and artists.
- The Expo app is the listener interface and is deferred until the portal is usable end to end.
- One user account may be a listener and hold memberships in several labels or artists.
- Roles grant scope; Laravel Policies enforce access on every protected operation.
- Labels and artists receive aggregate listener insights, never individual private collections.

## Delivery order

1. `B` — Laravel backend and API.
2. `P` — Next.js portal.
3. `M` — Expo listener app.

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
- [ ] `B2.2` Finalize the access-token and rotating refresh-token design.
- [ ] `B2.3` Extend users and profiles with required, minimal personal data.
- [ ] `B2.4` Implement `POST /api/v1/auth/register`.
- [ ] `B2.5` Configure Resend and implement the email-verification and resend flow.
  - [ ] `B2.5a` Install the supported Resend transport, validate required environment
    variables and keep API keys and webhook secrets outside Git.
  - [ ] `B2.5b` Configure a dedicated sending subdomain and document SPF, DKIM,
    DMARC, sender and reply-to requirements for each environment.
  - [ ] `B2.5c` Create a shared, accessible Uncovr transactional-email layout with
    HTML and plain-text versions, safe URLs and local previews.
  - [ ] `B2.5d` Queue transactional mail after database commit with controlled
    retries, failure visibility and deterministic Resend idempotency keys.
  - [ ] `B2.5e` Make verification links signed, expiring and single-use; throttle
    resends and return enumeration-safe API responses.
- [ ] `B2.6` Implement `POST /api/v1/auth/login` with device information.
- [ ] `B2.7` Implement refresh-token rotation and reuse detection.
- [ ] `B2.8` Implement current-device logout and logout from all devices.
- [ ] `B2.9` Implement forgotten-password and password-reset flows with queued,
  expiring and single-use Resend notifications.
- [ ] `B2.10` Implement `GET/PATCH /api/v1/me`.
- [ ] `B2.11` Implement session listing and per-session revocation.
- [ ] `B2.12` Add authentication throttling, audit events and safe error messages.
- [ ] `B2.13` Test registration, verification, login, refresh, revocation and reset
  end to end, including mail recipients, queued notifications, rendered content,
  link expiry, replay rejection and resend throttling.
- [ ] `B2.14` Add an explicit staging smoke test for real Resend delivery to a
  controlled address; automated tests and CI must always fake mail and perform
  no external sends.

### B2 gate

- [ ] A listener can register, verify, log in, refresh and log out.
- [ ] A revoked or reused token cannot access protected endpoints.
- [ ] Passwords and tokens are never stored in plaintext.
- [ ] Verification and password-reset emails render correctly, arrive through
  Resend in staging and cannot be replayed after use or expiry.
- [ ] Automated tests never contact Resend or send real email.

## B3 — Organizations, artists, roles and authorization

- [ ] `B3.1` Create organizations and organization profiles.
- [ ] `B3.2` Create organization memberships with `label_admin` and `label_user` roles.
- [ ] `B3.3` Create organization invitations with expiry, single-use acceptance
  and a queued Resend invitation notification.
- [ ] `B3.4` Create artists and artist profiles.
- [ ] `B3.5` Create artist memberships with `artist_admin` and `artist_user` roles.
- [ ] `B3.6` Create organization-to-artist relationships without hard-coding permanent ownership.
- [ ] `B3.7` Implement platform-level superadmin authorization.
- [ ] `B3.8` Add creator, owner, managing party and explicit editor concepts to editable resources.
- [ ] `B3.9` Implement Laravel Policies for organizations, artists and memberships.
- [ ] `B3.10` Ensure access can be granted at multiple scopes to the same user.
- [ ] `B3.11` Log invitations, role changes, suspensions and membership removal.
- [ ] `B3.12` Add a complete authorization matrix as automated feature tests.
- [ ] `B3.13` Test invitation email recipients, content, authorization, expiry,
  resend behavior and replay protection without making external mail requests.

### B3 gate

- [ ] Superadmin can see and manage the whole platform.
- [ ] Label Admin can manage only its label, artists and team.
- [ ] Artist Admin can manage only its artist and team.
- [ ] Label User and Artist User can change only owned or assigned resources.
- [ ] Removing membership immediately removes its access.

## B4 — Release and content domain

- [ ] `B4.1` Create releases with album, EP and single types.
- [ ] `B4.2` Create release-to-artist relationships with a primary artist.
- [ ] `B4.3` Create ordered tracks.
- [ ] `B4.4` Create ordered pages belonging to releases or tracks.
- [ ] `B4.5` Create versioned, validated content blocks.
- [ ] `B4.6` Support initial block types: heading, text, image, gallery, video, quote and lyrics.
- [ ] `B4.7` Create streaming links with validated service and URL fields.
- [ ] `B4.8` Create structured credits and contributor information.
- [ ] `B4.9` Create media records independently of storage implementation.
- [ ] `B4.10` Preserve creator, owner, editors and modification history.
- [ ] `B4.11` Implement CRUD endpoints protected by B3 policies.
- [ ] `B4.12` Test ordering, ownership, assignments and cross-tenant isolation.

### B4 gate

- [ ] An authorized artist or label user can create a complete draft release.
- [ ] The draft can contain tracks, pages, blocks, links, media references and credits.
- [ ] Users outside the owning scope cannot read drafts or modify content.

## B5 — Media, approval and publishing

- [ ] `B5.1` Define private and public Supabase Storage buckets and retention rules.
- [ ] `B5.2` Implement authorized upload requests without exposing server secrets.
- [ ] `B5.3` Validate MIME type, size, ownership and image metadata.
- [ ] `B5.4` Implement safe replacement and deletion of media.
- [ ] `B5.5` Add release states: draft, review, scheduled, published, unpublished and archived.
- [ ] `B5.6` Implement approval requests and decisions.
- [ ] `B5.7` Implement permission-controlled publishing and unpublishing.
- [ ] `B5.8` Implement scheduled publication through queues.
- [ ] `B5.9` Produce immutable activity records for sensitive publication actions.
- [ ] `B5.10` Test upload authorization, approval transitions and publishing rules.

### B5 gate

- [ ] A draft can be previewed, submitted, approved and published.
- [ ] Only authorized roles can approve or publish.
- [ ] Published content remains separate from private drafts.

## B6 — Public content API and discovery foundation

- [ ] `B6.1` Implement public label, artist, release and track representations.
- [ ] `B6.2` Return only published and currently available content.
- [ ] `B6.3` Add paginated search for labels, artists and releases.
- [ ] `B6.4` Add featured and recent-release endpoints.
- [ ] `B6.5` Add cache headers and safe server-side caching.
- [ ] `B6.6` Prevent drafts, internal notes and private membership data from leaking.
- [ ] `B6.7` Add contract tests for all public representations.

## B7 — Listener domain, privacy and notifications

This backend work may begin after the portal gate, immediately before Expo work.

- [ ] `B7.1` Implement unique artist follows.
- [ ] `B7.2` Implement unique release and track favorites.
- [ ] `B7.3` Implement private collections and ordered collection items.
- [ ] `B7.4` Implement notification preferences by channel and topic, keeping
  required account/security email separate from optional marketing consent.
- [ ] `B7.5` Register devices and deactivate push tokens on logout or deletion.
- [ ] `B7.6` Create an in-app notification model and paginated endpoint.
- [ ] `B7.7` Implement user data export.
- [ ] `B7.8` Implement documented account deletion and retention workflow.
- [ ] `B7.9` Record required consent without mixing operational and marketing messages.
- [ ] `B7.10` Expose only aggregate follow and favorite statistics to labels and artists.
- [ ] `B7.11` Test that one listener can never read another listener's private data.

## B8 — Operations and backend release readiness

- [ ] `B8.1` Configure production queues, retries and failed-job handling.
- [ ] `B8.2` Make Resend transactional email production-ready.
  - [ ] `B8.2a` Configure scoped production credentials, verified sending
    subdomain, SPF, DKIM, DMARC, sender identities and secret rotation.
  - [ ] `B8.2b` Receive Resend delivery webhooks over HTTPS and verify the raw
    request signature before processing any event.
  - [ ] `B8.2c` Make webhook processing idempotent using `svix-id` and tolerate
    duplicate and out-of-order delivery.
  - [ ] `B8.2d` Store only necessary provider message IDs and delivery state;
    handle delivered, bounced, complained, suppressed and failed outcomes.
  - [ ] `B8.2e` Add metrics and alerts for queue failures, provider errors and
    abnormal bounce or complaint rates without logging email bodies or secrets.
  - [ ] `B8.2f` Test valid, invalid, replayed, duplicate and out-of-order webhooks,
    then run a documented production-like delivery and bounce smoke test.
- [ ] `B8.3` Add error monitoring and production-safe logging.
- [ ] `B8.4` Document backup, restore and incident procedures.
- [ ] `B8.5` Add database security and performance checks to release procedure.
- [ ] `B8.6` Publish machine-readable API documentation.
- [ ] `B8.7` Seed a deterministic demo label, users, artists and release.
- [ ] `B8.8` Run the complete test suite against a production-like environment.

## Backend phase gate

- [ ] Superadmin can create or approve a label and inspect its hierarchy through the API.
- [ ] Label Admin can manage team members and create an artist.
- [ ] Artist Admin can manage its team and create a release.
- [ ] A release can progress from draft to published.
- [ ] Unauthorized and cross-tenant API operations are rejected and tested.
- [ ] Database, queue, email, storage and monitoring integrations are documented.
- [ ] Resend domain authentication, queued sending, idempotency, signed webhooks,
  bounce handling and alerting have passed a production-like verification.

---

# P — Next.js portal

Portal work begins only after the backend phase gate passes for B1 through B6.

## P0 — Portal foundation

- [ ] `P0.1` Decide and document whether the portal uses a separate repository or workspace.
- [ ] `P0.2` Scaffold current stable Next.js with TypeScript and pinned dependencies.
- [ ] `P0.3` Add formatting, linting, tests and CI.
- [ ] `P0.4` Configure environment validation and the Laravel API base URL.
- [ ] `P0.5` Build a typed API client with consistent error handling.
- [ ] `P0.6` Establish accessible layout, forms, feedback and responsive breakpoints.

## P1 — Authentication and role-aware shell

- [ ] `P1.1` Build registration, login, verification and password-reset screens.
- [ ] `P1.2` Integrate Laravel's secure cookie and CSRF flow.
- [ ] `P1.3` Build account, active-session and logout screens.
- [ ] `P1.4` Load the current user's memberships and available workspaces.
- [ ] `P1.5` Build role-aware navigation without treating hidden UI as authorization.
- [ ] `P1.6` Add forbidden, expired-session, empty and error states.

## P2 — Superadmin workflow

- [ ] `P2.1` Build platform overview and operational status.
- [ ] `P2.2` Build user, organization, artist and release search.
- [ ] `P2.3` Build organization creation, approval, suspension and correction flows.
- [ ] `P2.4` Show user memberships and resource hierarchy.
- [ ] `P2.5` Build role correction and account suspension with confirmation and audit context.
- [ ] `P2.6` Verify that superadmin operations use protected Laravel endpoints only.

### P2 gate

- [ ] A superadmin can establish a label and its first administrator entirely in the portal.

## P3 — Label workflow

- [ ] `P3.1` Build label dashboard and profile editor.
- [ ] `P3.2` Build team listing, invitations, role changes and removals.
- [ ] `P3.3` Build artist listing and artist creation.
- [ ] `P3.4` Assign an Artist Admin during artist onboarding.
- [ ] `P3.5` Show all permitted releases across label artists.
- [ ] `P3.6` Verify Label User restrictions for owned and assigned work.

### P3 gate

- [ ] Label Admin can manage its label, team and artists without developer help.
- [ ] Label User cannot administer team members or unrestricted content.

## P4 — Artist workflow

- [ ] `P4.1` Build artist dashboard and profile editor.
- [ ] `P4.2` Build artist team invitations, roles and removal.
- [ ] `P4.3` Build release listing with status, ownership and assignment filters.
- [ ] `P4.4` Build release creation and basic metadata editing.
- [ ] `P4.5` Verify Artist User restrictions for owned and assigned work.

### P4 gate

- [ ] Artist Admin can manage its profile, team and releases.
- [ ] Artist User cannot administer roles or alter another user's unassigned work.

## P5 — Release builder

- [ ] `P5.1` Build release metadata, artist and date forms.
- [ ] `P5.2` Build sortable track management.
- [ ] `P5.3` Build page management for releases and tracks.
- [ ] `P5.4` Build the first accessible block-editor interface.
- [ ] `P5.5` Build media upload, selection, replacement and removal.
- [ ] `P5.6` Build streaming-link and credit editors.
- [ ] `P5.7` Build responsive preview using the public release representation.
- [ ] `P5.8` Build review, approval, scheduling, publishing and unpublishing controls.
- [ ] `P5.9` Preserve unsaved-work warnings and actionable validation feedback.

## P6 — Portal quality and demo readiness

- [ ] `P6.1` Add aggregate statistics without exposing listener identities.
- [ ] `P6.2` Add loading, empty, offline and recoverable error states.
- [ ] `P6.3` Verify keyboard navigation and screen-reader fundamentals.
- [ ] `P6.4` Verify phone, tablet and desktop layouts.
- [ ] `P6.5` Add end-to-end tests for superadmin, label and artist journeys.
- [ ] `P6.6` Run an authorization-focused security review.
- [ ] `P6.7` Deploy a production-like portal connected to a safe environment.

## Portal phase gate

- [ ] Superadmin creates or approves a label and its administrator.
- [ ] Label Admin invites team members and creates an artist with an Artist Admin.
- [ ] Artist Admin creates a release with tracks, media, credits and rich content.
- [ ] The release is previewed, approved and published entirely through the portal.
- [ ] Lower-privileged users are blocked from forbidden actions in both UI and API.
- [ ] The complete workflow passes automated end-to-end tests.

Only after this gate passes do we schedule Expo implementation.

---

# M — Expo listener app (future)

## M0 — Mobile planning gate

- [ ] `M0.1` Revalidate listener journeys against pilot feedback.
- [ ] `M0.2` Freeze the mobile API contracts required for the first build.
- [ ] `M0.3` Define supported iOS and Android versions and accessibility baseline.
- [ ] `M0.4` Scaffold Expo/React Native with pinned dependencies and CI builds.

## M1 — Mobile identity

- [ ] `M1.1` Build registration, verification, login and password reset.
- [ ] `M1.2` Store access and refresh credentials in secure device storage.
- [ ] `M1.3` Implement refresh rotation, logout and revoked-session handling.
- [ ] `M1.4` Build profile, active-device and privacy controls.

## M2 — Discovery and release experience

- [ ] `M2.1` Build home, featured and recent content.
- [ ] `M2.2` Build search for labels, artists and releases.
- [ ] `M2.3` Build label and artist profiles.
- [ ] `M2.4` Build release, track, page and content-block rendering.
- [ ] `M2.5` Open music in the listener's preferred streaming service.
- [ ] `M2.6` Add sharing and deep links.

## M3 — Personal listener features

- [ ] `M3.1` Follow and unfollow artists idempotently.
- [ ] `M3.2` Favorite releases and tracks idempotently.
- [ ] `M3.3` Build private, sortable collections.
- [ ] `M3.4` Synchronize changes across at least two devices.
- [ ] `M3.5` Handle pagination, server time and conflicting updates safely.

## M4 — Notifications, privacy and launch

- [ ] `M4.1` Register and deactivate push-notification devices.
- [ ] `M4.2` Build notification center and preference controls.
- [ ] `M4.3` Implement data export and account deletion in the app.
- [ ] `M4.4` Add analytics with documented consent and data minimization.
- [ ] `M4.5` Complete accessibility, performance and security testing.
- [ ] `M4.6` Prepare App Store and Google Play requirements.
- [ ] `M4.7` Run a pilot with one label, two to five artists and real listeners.

---

# Explicitly deferred beyond MVP

- Own music streaming.
- Royalty and complex contract management.
- Automatic music distribution.
- Full label CRM.
- Social feed, comments and direct messages.
- Advanced recommendation engine.
- Fan clubs, tickets and merchandise.
- Public or collaborative collections until privacy and moderation are ready.
- Passkeys, social login and two-factor authentication until the core identity flow is stable.

# Decision log

Record meaningful decisions here so later Codex sessions do not reopen them
without new evidence.

| Date | Decision | Reason |
|---|---|---|
| 2026-08-07 | Laravel owns authentication; Supabase Auth is not used. | Keeps identity, roles and business authorization in one system. |
| 2026-08-07 | Laravel data uses the private `laravel` schema. | Prevents accidental exposure through Supabase Data API. |
| 2026-08-07 | Next.js is the only operational UI for platform, label and artist users. | Avoids duplicate Laravel and Next.js admin interfaces. |
| 2026-08-07 | Expo begins only after the full portal release workflow passes. | Proves content production before building the listener client. |
