# Uncovr Backend

Laravel API for Uncovr 2.0, a platform for interactive digital music releases.

Laravel owns authentication, authorization and domain logic. PostgreSQL and
object storage are hosted by Supabase. The React portal and future Expo app
will communicate with this API instead of accessing the database directly.

## Product surfaces

- Laravel API: identity, access control, organizations, artists, releases and publishing.
- React portal: working interface for superadmins, labels and artists.
- Expo app: listener experience, intentionally deferred until the portal workflow is complete.

## Development roadmap

The authoritative, checkable implementation sequence is in
[docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md).

Backend conventions are documented in:

- [docs/API_CONVENTIONS.md](docs/API_CONVENTIONS.md)
- [docs/AUTHENTICATION.md](docs/AUTHENTICATION.md)
- [docs/IDENTIFIERS.md](docs/IDENTIFIERS.md)
- [docs/QUEUE_OPERATIONS.md](docs/QUEUE_OPERATIONS.md)
- [docs/EMAIL_DELIVERY.md](docs/EMAIL_DELIVERY.md)
- [docs/OBSERVABILITY.md](docs/OBSERVABILITY.md)
- [docs/OPERATIONS_RUNBOOK.md](docs/OPERATIONS_RUNBOOK.md)
- [docs/RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md)
- [docs/RELEASE_READINESS_EVIDENCE.md](docs/RELEASE_READINESS_EVIDENCE.md)

Work should proceed from the first unchecked task in the active milestone. A
phase gate must be satisfied before work starts on the next product surface.

## Requirements

- PHP 8.4.1 or newer with PDO SQLite and PDO PostgreSQL.
- Composer 2.
- Node.js 24 and npm for frontend assets.
- Access to the active Supabase project when running the application locally.

## Fresh checkout

```bash
git clone git@github.com:oddedodd/uncovr2-backend.git
cd uncovr2-backend
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Add the Supabase Session Pooler connection to `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_URL="postgresql://postgres.PROJECT_REF:YOUR_PASSWORD@POOLER_HOST:5432/postgres"
DB_SCHEMA=laravel
DB_SSLMODE=require
```

The password and complete connection string belong only in `.env` and must
never be committed. Then prepare and build the application:

```bash
php artisan migrate
npm run build
composer test
```

Start local development with:

```bash
composer dev
```

## Tests and quality checks

Local tests use an in-memory SQLite database configured in `phpunit.xml` and do
not connect to Supabase, even when `.env` contains production credentials. CI
also runs the complete suite on PostgreSQL 17 in the private `laravel` schema.

```bash
composer validate --strict
composer audit --locked --no-interaction
./vendor/bin/pint --test
php artisan test
```

GitHub Actions runs the same validation, audit, formatting and test checks for
every pull request and every push to `main`. Its production-like job also seeds
the deterministic demo account hierarchy and runs `release:check`.
