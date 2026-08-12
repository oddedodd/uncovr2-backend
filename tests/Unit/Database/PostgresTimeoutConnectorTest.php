<?php

namespace Tests\Unit\Database;

use App\Database\PostgresTimeoutConnector;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

class PostgresTimeoutConnectorTest extends TestCase
{
    public function test_timeouts_are_folded_into_the_search_path_statement(): void
    {
        $connection = $this->recordingConnection();

        $this->configure($connection, [
            'search_path' => 'laravel',
            'statement_timeout' => '15s',
            'lock_timeout' => '5s',
            'idle_in_transaction_session_timeout' => '30s',
        ]);

        $this->assertSame(
            ['set search_path to "laravel"; set statement_timeout = \'15s\'; set lock_timeout = \'5s\'; set idle_in_transaction_session_timeout = \'30s\''],
            $connection->statements,
            'All connection settings must travel in a single round trip.',
        );
    }

    public function test_search_path_alone_still_works_when_no_timeout_is_configured(): void
    {
        $connection = $this->recordingConnection();

        $this->configure($connection, ['search_path' => 'laravel']);

        $this->assertSame(['set search_path to "laravel"'], $connection->statements);
    }

    public function test_nothing_is_issued_when_neither_is_configured(): void
    {
        $connection = $this->recordingConnection();

        $this->configure($connection, []);

        $this->assertSame([], $connection->statements);
    }

    public function test_a_non_duration_timeout_is_rejected_rather_than_interpolated(): void
    {
        $connection = $this->recordingConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->configure($connection, [
            'search_path' => 'laravel',
            'statement_timeout' => "15s'; drop table users; --",
        ]);
    }

    private function configure(object $connection, array $config): void
    {
        (new ReflectionMethod(PostgresTimeoutConnector::class, 'configureSearchPath'))
            ->invoke(new PostgresTimeoutConnector, $connection, $config);
    }

    private function recordingConnection(): object
    {
        return new class
        {
            /** @var array<int, string> */
            public array $statements = [];

            public function exec(string $statement): int
            {
                $this->statements[] = $statement;

                return 0;
            }
        };
    }
}
