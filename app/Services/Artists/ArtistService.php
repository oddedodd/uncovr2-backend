<?php

namespace App\Services\Artists;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ArtistService
{
    public function create(User $creator, array $profile, ?string $creatorRole = null): Artist
    {
        return DB::transaction(function () use ($creator, $profile, $creatorRole): Artist {
            $artist = Artist::query()->create(['created_by_user_id' => $creator->getKey()]);
            $artist->profile()->create($profile);
            if ($creatorRole !== null) {
                $artist->memberships()->create([
                    'user_id' => $creator->getKey(),
                    'role' => ArtistRole::from($creatorRole)->value,
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                ]);
            }

            return $artist->load('profile');
        });
    }
}
