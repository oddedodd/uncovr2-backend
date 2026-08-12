<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;
use InvalidArgumentException;

/**
 * Applies Postgres timeouts without adding a round trip.
 *
 * Laravel has no native timeout configuration. The libpq `options` connection
 * parameter would be free, but Supabase's Supavisor pooler discards client
 * startup parameters, so the settings must be issued as statements after
 * connecting. Laravel already spends one round trip on `set search_path`, so the
 * timeouts are folded into that same statement and cost nothing extra.
 */
final class PostgresTimeoutConnector extends PostgresConnector
{
    private const TIMEOUT_KEYS = [
        'statement_timeout',
        'lock_timeout',
        'idle_in_transaction_session_timeout',
    ];

    protected function configureSearchPath($connection, $config)
    {
        $statements = [];

        if (isset($config['search_path']) || isset($config['schema'])) {
            $searchPath = $this->quoteSearchPath(
                $this->parseSearchPath($config['search_path'] ?? $config['schema'])
            );

            $statements[] = "set search_path to {$searchPath}";
        }

        foreach (self::TIMEOUT_KEYS as $key) {
            $value = $config[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $statements[] = "set {$key} = '{$this->timeout($key, $value)}'";
        }

        if ($statements === []) {
            return;
        }

        // exec() uses the simple query protocol, which allows several statements
        // in one message. prepare()/execute() would allow only one.
        $connection->exec(implode('; ', $statements));
    }

    /**
     * Timeouts are interpolated into a statement, so only plain Postgres
     * durations are accepted.
     */
    private function timeout(string $key, mixed $value): string
    {
        $value = (string) $value;

        if (! preg_match('/^\d+(ms|s|min)?$/', $value)) {
            throw new InvalidArgumentException(
                "The database [{$key}] must be a plain Postgres duration such as \"15s\", got \"{$value}\"."
            );
        }

        return $value;
    }
}
