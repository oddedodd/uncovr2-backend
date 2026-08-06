# Uncovr Backend

Laravel API for Uncovr 2.0, a platform for interactive digital music releases.

Laravel owns authentication, authorization and domain logic. PostgreSQL and
object storage are hosted by Supabase. The future Next.js portal and Expo app
will communicate with this API instead of accessing the database directly.

## Product surfaces

- Laravel API: identity, access control, organizations, artists, releases and publishing.
- Next.js portal: working interface for superadmins, labels and artists.
- Expo app: listener experience, intentionally deferred until the portal workflow is complete.

## Development roadmap

The authoritative, checkable implementation sequence is in
[docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md).

Work should proceed from the first unchecked task in the active milestone. A
phase gate must be satisfied before work starts on the next product surface.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

Database credentials belong in `.env` and must never be committed.
