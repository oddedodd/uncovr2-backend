<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckReleaseReadiness extends Command
{
    protected $signature = 'release:check {--production-like : Enforce production integration configuration} {--json : Emit machine-readable output}';

    protected $description = 'Check application and database release-readiness invariants';

    /** @var list<array{name: string, status: string, detail: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $this->checkDatabaseConnection();
        $this->checkRequiredTables();
        $this->checkPendingMigrations();
        $this->checkQueueConfiguration();

        if (DB::getDriverName() === 'pgsql') {
            $this->checkPrivateSchemaGrants();
            $this->checkInvalidIndexes();
            $this->checkForeignKeyIndexes();
        }

        if ($this->option('production-like')) {
            $this->checkProductionConfiguration();
        }

        $failed = collect($this->checks)->contains('status', 'failed');
        $result = ['status' => $failed ? 'failed' : 'ready', 'checks' => $this->checks];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            foreach ($this->checks as $check) {
                $this->components->twoColumnDetail(
                    $check['name'],
                    strtoupper($check['status']).' — '.$check['detail'],
                );
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDatabaseConnection(): void
    {
        try {
            DB::select('SELECT 1');
            $this->pass('database_connection', DB::getDriverName());
        } catch (Throwable $exception) {
            $this->recordFailure('database_connection', $exception::class);
        }
    }

    private function checkRequiredTables(): void
    {
        $required = [
            'users', 'jobs', 'failed_jobs', 'organizations', 'artists', 'releases',
            'release_publications', 'email_deliveries', 'email_webhook_events',
        ];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));

        $missing === []
            ? $this->pass('required_tables', (string) count($required))
            : $this->recordFailure('required_tables', implode(',', $missing));
    }

    private function checkPendingMigrations(): void
    {
        $ran = Schema::hasTable('migrations')
            ? DB::table('migrations')->pluck('migration')->all()
            : [];
        $files = collect(glob(database_path('migrations/*.php')) ?: [])
            ->map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME))
            ->all();
        $pending = array_values(array_diff($files, $ran));

        $pending === []
            ? $this->pass('pending_migrations', '0')
            : $this->recordFailure('pending_migrations', implode(',', $pending));
    }

    private function checkQueueConfiguration(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $timeout = (int) config('queue.worker.timeout');
        $valid = $retryAfter > $timeout && config('queue.failed.driver') === 'database-uuids';

        $valid
            ? $this->pass('queue_configuration', "timeout={$timeout},retry_after={$retryAfter}")
            : $this->recordFailure('queue_configuration', "timeout={$timeout},retry_after={$retryAfter}");
    }

    private function checkPrivateSchemaGrants(): void
    {
        $count = (int) DB::scalar(<<<'SQL'
            WITH api_roles(role_name) AS (
                VALUES ('anon'), ('authenticated'), ('service_role')
            ), unsafe AS (
                SELECT role_name, 'schema' AS object_type
                FROM api_roles
                WHERE has_schema_privilege(role_name, current_schema(), 'USAGE')
                   OR has_schema_privilege(role_name, current_schema(), 'CREATE')
                UNION ALL
                SELECT grantee, 'table'
                FROM information_schema.role_table_grants
                WHERE table_schema = current_schema()
                  AND grantee IN ('anon', 'authenticated', 'service_role')
                UNION ALL
                SELECT grantee, 'sequence'
                FROM information_schema.role_usage_grants
                WHERE object_schema = current_schema()
                  AND object_type = 'SEQUENCE'
                  AND grantee IN ('anon', 'authenticated', 'service_role')
            )
            SELECT count(*) FROM unsafe
        SQL);

        $count === 0
            ? $this->pass('private_schema_grants', '0')
            : $this->recordFailure('private_schema_grants', (string) $count);
    }

    private function checkInvalidIndexes(): void
    {
        $count = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_index i
            JOIN pg_class t ON t.oid = i.indrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE n.nspname = current_schema() AND NOT i.indisvalid
        SQL);

        $count === 0
            ? $this->pass('invalid_indexes', '0')
            : $this->recordFailure('invalid_indexes', (string) $count);
    }

    private function checkForeignKeyIndexes(): void
    {
        $count = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_constraint c
            JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE c.contype = 'f'
              AND n.nspname = current_schema()
              AND NOT EXISTS (
                SELECT 1
                FROM pg_index i
                WHERE i.indrelid = c.conrelid
                  AND i.indisvalid
                  AND (
                    SELECT array_agg(k.attnum::smallint ORDER BY k.ordinality)
                    FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ordinality)
                    WHERE k.ordinality <= cardinality(c.conkey)
                  ) = c.conkey
              )
        SQL);

        $count === 0
            ? $this->pass('foreign_key_indexes', '0')
            : $this->recordFailure('foreign_key_indexes', (string) $count);
    }

    private function checkProductionConfiguration(): void
    {
        $checks = [
            'app_debug_disabled' => config('app.debug') === false,
            'https_app_url' => str_starts_with((string) config('app.url'), 'https://'),
            'resend_mailer' => config('mail.default') === 'resend',
            'resend_api_key' => filled(config('services.resend.key')),
            'resend_webhook_secret' => str_starts_with((string) config('email.webhook.secret'), 'whsec_'),
            'resend_webhook_https' => str_starts_with((string) config('email.webhook.url'), 'https://'),
            'database_queue' => config('queue.default') === 'database',
            'stderr_logging' => in_array('stderr', config('logging.channels.stack.channels', []), true),
            'supabase_storage_url' => str_starts_with((string) config('services.supabase.url'), 'https://'),
            'supabase_storage_secret' => filled(config('services.supabase.secret_key')),
        ];

        foreach ($checks as $name => $valid) {
            $valid ? $this->pass($name, 'configured') : $this->recordFailure($name, 'not configured');
        }

        $this->checkCredentialAge('resend_api_key_age', config('email.credential_rotation.api_key_rotated_at'));
        $this->checkCredentialAge('resend_webhook_secret_age', config('email.credential_rotation.webhook_secret_rotated_at'));
    }

    private function checkCredentialAge(string $name, mixed $date): void
    {
        try {
            $rotatedAt = is_string($date) && $date !== '' ? CarbonImmutable::parse($date) : null;
        } catch (Throwable) {
            $rotatedAt = null;
        }

        $maxAge = max(1, (int) config('email.credential_rotation.max_age_days'));
        $valid = $rotatedAt !== null && $rotatedAt->isAfter(now()->subDays($maxAge));

        $valid
            ? $this->pass($name, $rotatedAt->toDateString())
            : $this->recordFailure($name, 'missing, invalid or expired');
    }

    private function pass(string $name, string $detail): void
    {
        $this->checks[] = compact('name', 'detail') + ['status' => 'passed'];
    }

    private function recordFailure(string $name, string $detail): void
    {
        $this->checks[] = compact('name', 'detail') + ['status' => 'failed'];
    }
}
