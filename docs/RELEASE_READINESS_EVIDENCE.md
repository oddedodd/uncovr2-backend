# Backend release-readiness evidence

Gate completed on 2026-08-09 for the backend state at commit `533f53b`.

## Automated verification

- GitHub Actions CI run [#30](https://github.com/oddedodd/uncovr2-backend/actions/runs/31315831285) passed both jobs.
- The standard quality job passed dependency validation and audits, PHP syntax,
  formatting, 166 tests, 1,487 assertions and the Vite production build.
- The production-like job passed the complete suite on PostgreSQL 17 using the
  private `laravel` schema and Supabase-compatible `anon`, `authenticated` and
  `service_role` roles.
- The same job migrated from empty, seeded the deterministic demo hierarchy
  twice, verified the committed OpenAPI document, passed production
  configuration checks and returned a healthy operational status.
- Signed Resend HTTP tests cover valid, invalid, expired, replayed, duplicate,
  out-of-order, delivered and bounced events without external customer data.

## Active Supabase verification

Target: `Uncovr-db`, project ref `fwzfomvmtctpxovzilqw`. The paused legacy
project `Uncovr` was not used.

- Migration `2026_08_09_220000_create_email_delivery_operations` applied.
- `release:check --json` returned `ready`: PostgreSQL connected, all required
  tables present, zero pending migrations, safe queue timing, zero API-role
  grants in the private schema, zero invalid indexes and zero unindexed foreign
  keys.
- `operations:check --json --no-alert` returned `ok` with no active alerts.
- Supabase Security Advisor returned zero findings.
- Supabase Performance Advisor returned only 109 `INFO`-level `unused_index`
  notices. These are expected before representative traffic and are retained
  until query evidence supports removal.

## Provider evidence

The sending subdomain, DKIM, SPF, return-path MX and DMARC configuration are
documented in `EMAIL_DELIVERY.md`. A controlled Resend message was accepted and
inbox receipt was confirmed earlier in B2. The B8 production-like gate verifies
the signed delivery/bounce webhook path without making an uncontrolled external
send.

## P3 onboarding follow-up

The onboarding extension was verified on 2026-08-09 from code commits `305290e`
and `7473eb1`:

- migration `2026_08_09_240000_create_artist_invitations` was applied to
  `Uncovr-db` as batch 16 with zero pending migrations;
- the private table, foreign keys, role and send-count constraints, token and
  public-ID uniqueness, and partial unique pending-invitation index were
  inspected successfully;
- 193 tests with 1,720 assertions passed, including authorization, users that
  exist before or are created after invitation, expiry, replay rejection,
  resend rotation, queued encrypted notifications and explicit transaction
  rollback tests;
- the committed OpenAPI document covered the two onboarding commands and all
  artist-invitation commands; and
- Laravel/PostgreSQL remains the provider-neutral client contract; no Supabase
  database credential, private table or Data API client is exposed to the portal.

The database portion of `release:check --production-like --json` passed after
this migration: PostgreSQL connectivity, required tables, pending migrations,
private-schema grants, invalid indexes and foreign-key indexes were all clean.
The developer machine is not a production deployment and lacks several
deployment-only HTTPS, stderr logging, Resend webhook and secret-rotation
values, so that local run is not recorded as a new overall production-ready
result. The historical CI gate above remains the production-like evidence until
the next deployment environment run.
