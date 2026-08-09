<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_feature_tests_use_an_isolated_test_database(): void
    {
        $connection = config('database.default');

        $this->assertContains($connection, ['sqlite', 'pgsql']);
        if ($connection === 'sqlite') {
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        } else {
            $this->assertSame('testing', app()->environment());
            $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
            $this->assertSame('uncovr_test', config('database.connections.pgsql.database'));
            $this->assertSame('laravel', config('database.connections.pgsql.search_path'));
        }
        $this->assertContains(RefreshDatabase::class, class_uses_recursive(TestCase::class));

        $this->assertDatabaseCount('users', 0);

        User::factory()->create();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_each_feature_test_starts_without_rows_from_the_previous_test(): void
    {
        $this->assertDatabaseCount('users', 0);
    }
}
