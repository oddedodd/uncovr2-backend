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
