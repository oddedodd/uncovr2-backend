<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CorrectArtistMembershipRoleRequest;
use App\Http\Requests\Api\V1\CorrectOrganizationMembershipRoleRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ArtistMembership;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Authorization\MembershipService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UserMembershipRoleController extends Controller
{
    public function organization(
        CorrectOrganizationMembershipRoleRequest $request,
        User $user,
        OrganizationMembership $membership,
        MembershipService $service,
    ): JsonResponse {
        $this->assertUser($user, $membership->user_id);
        $updated = $service->updateOrganization(
            $membership,
            ['role' => $request->string('role')->toString()],
            $request->user(),
            $request,
            [
                'target_user_id' => $user->public_id,
                'reason' => $request->string('reason')->toString(),
                'correction_source' => 'platform_administration',
            ],
        )->load('organization.profile');

        return ApiResponse::success([
            'id' => $updated->public_id,
            'type' => 'organization',
            'scope' => [
                'id' => $updated->organization->public_id,
                'name' => $updated->organization->profile->name,
            ],
            'user_id' => $user->public_id,
            'role' => $updated->role->value,
            'status' => $updated->status->value,
        ]);
    }

    public function artist(
        CorrectArtistMembershipRoleRequest $request,
        User $user,
        ArtistMembership $membership,
        MembershipService $service,
    ): JsonResponse {
        $this->assertUser($user, $membership->user_id);
        $updated = $service->updateArtist(
            $membership,
            ['role' => $request->string('role')->toString()],
            $request->user(),
            $request,
            [
                'target_user_id' => $user->public_id,
                'reason' => $request->string('reason')->toString(),
                'correction_source' => 'platform_administration',
            ],
        )->load('artist.profile');

        return ApiResponse::success([
            'id' => $updated->public_id,
            'type' => 'artist',
            'scope' => [
                'id' => $updated->artist->public_id,
                'name' => $updated->artist->profile->name,
            ],
            'user_id' => $user->public_id,
            'role' => $updated->role->value,
            'status' => $updated->status->value,
        ]);
    }

    private function assertUser(User $user, int $membershipUserId): void
    {
        if ($membershipUserId !== $user->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
