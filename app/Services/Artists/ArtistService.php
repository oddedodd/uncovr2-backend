<?php

namespace App\Services\Artists;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ArtistService
{
    public function create(User $creator, array $profile): Artist
    {
        return DB::transaction(function () use ($creator, $profile): Artist {
            $artist = Artist::query()->create(['created_by_user_id' => $creator->getKey()]);
            $artist->profile()->create($profile);
            $artist->memberships()->create([
                'user_id' => $creator->getKey(),
                'role' => ArtistRole::Admin->value,
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
            ]);

            return $artist->load('profile');
        });
    }
}
