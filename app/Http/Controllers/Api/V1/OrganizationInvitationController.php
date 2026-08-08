<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptOrganizationInvitationRequest;
use App\Http\Requests\Api\V1\InviteOrganizationMemberRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\Organizations\OrganizationInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OrganizationInvitationController extends Controller
{
    public function store(InviteOrganizationMemberRequest $request, Organization $organization, OrganizationInvitationService $service): JsonResponse
    {
        Gate::authorize('manageMembers', $organization);
        $invitation = $service->create(
            $organization,
            $request->user(),
            $request,
            $request->string('email')->toString(),
            $request->string('role')->toString(),
        );

        return ApiResponse::success($this->resource($invitation), 201);
    }

    public function resend(Request $request, OrganizationInvitation $invitation, OrganizationInvitationService $service): JsonResponse
    {
        Gate::authorize('manageMembers', $invitation->organization);

        return ApiResponse::success($this->resource($service->resend($invitation, $request->user(), $request)));
    }

    public function accept(AcceptOrganizationInvitationRequest $request, OrganizationInvitationService $service): JsonResponse
    {
        $membership = $service->accept($request->string('token')->toString(), $request->user(), $request);

        return ApiResponse::success([
            'membership_id' => $membership->public_id,
            'organization_id' => $membership->organization->public_id,
            'role' => $membership->role->value,
        ]);
    }

    private function resource(OrganizationInvitation $invitation): array
    {
        return [
            'id' => $invitation->public_id,
            'organization_id' => $invitation->organization->public_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'last_sent_at' => $invitation->last_sent_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'send_count' => $invitation->send_count,
        ];
    }
}
