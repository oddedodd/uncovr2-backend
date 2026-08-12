<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateOrganizationMembershipRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Authorization\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrganizationMembershipController extends Controller
{
    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('manageMembers', $organization);

        return ApiResponse::success($organization->memberships()->with('user.profile')->orderBy('public_id')->get()->map(fn ($membership) => $this->resource($membership, $organization))->all());
    }

    public function update(UpdateOrganizationMembershipRequest $request, Organization $organization, OrganizationMembership $membership, MembershipService $service): JsonResponse
    {
        $this->assertParent($organization, $membership);
        Gate::authorize('update', $membership);

        return ApiResponse::success($this->resource($service->updateOrganization($membership, $request->validated(), $request->user(), $request), $organization));
    }

    public function destroy(Request $request, Organization $organization, OrganizationMembership $membership, MembershipService $service): JsonResponse
    {
        $this->assertParent($organization, $membership);
        Gate::authorize('delete', $membership);
        $service->removeOrganization($membership, $request->user(), $request);

        return ApiResponse::success(['message' => 'Membership removed.']);
    }

    private function assertParent(Organization $organization, OrganizationMembership $membership): void
    {
        if ($membership->organization_id !== $organization->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function resource(OrganizationMembership $membership, Organization $organization): array
    {
        $membership->loadMissing('user.profile');

        return [
            'id' => $membership->public_id,
            'organization_id' => $organization->public_id,
            'user' => ['id' => $membership->user->public_id, 'email' => $membership->user->email, 'display_name' => $membership->user->profile?->display_name],
            'role' => $membership->role->value,
            'status' => $membership->status->value,
        ];
    }
}
