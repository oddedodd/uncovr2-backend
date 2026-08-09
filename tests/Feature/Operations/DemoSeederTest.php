<?php

namespace Tests\Feature\Operations;

use App\Models\Artist;
use App\Models\Organization;
use App\Models\Release;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_is_deterministic_rerunnable_and_publicly_visible(): void
    {
        config(['demo.password' => 'Strong-Demo-Password!']);

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(3, User::query()->where('email', 'like', '%@demo.uncovr.test')->count());
        $this->assertSame(1, Organization::query()->whereHas('profile', fn ($query) => $query->where('name', 'North Star Records'))->count());
        $this->assertSame(1, Artist::query()->whereHas('profile', fn ($query) => $query->where('name', 'Aurora Lines'))->count());
        $this->assertSame(1, Release::query()->where('title', 'Northern Lights')->count());

        $release = Release::query()->where('title', 'Northern Lights')->sole();
        $this->getJson('/api/v1/public/releases/'.$release->public_id)
            ->assertOk()
            ->assertJsonPath('data.title', 'Northern Lights')
            ->assertJsonPath('data.artists.0.name', 'Aurora Lines')
            ->assertJsonPath('data.tracks.0.title', 'Northern Lights');
    }
}
