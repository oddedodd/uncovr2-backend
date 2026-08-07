<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_feature_tests_use_an_isolated_in_memory_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
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
