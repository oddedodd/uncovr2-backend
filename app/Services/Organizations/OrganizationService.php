<?php

namespace App\Services\Organizations;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OrganizationService
{
    public function create(User $creator, array $profile): Organization
    {
        return DB::transaction(function () use ($creator, $profile): Organization {
            $organization = Organization::query()->create(['created_by_user_id' => $creator->getKey()]);
            $organization->profile()->create($profile);
            $organization->memberships()->create([
                'user_id' => $creator->getKey(),
                'role' => OrganizationRole::Admin->value,
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
            ]);

            return $organization->load('profile');
        });
    }
}
