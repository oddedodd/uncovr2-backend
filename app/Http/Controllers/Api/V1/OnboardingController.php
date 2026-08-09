<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreArtistOnboardingRequest;
use App\Http\Requests\Api\V1\StoreOrganizationOnboardingRequest;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Responses\ApiResponse;
use App\Models\ArtistInvitation;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class OnboardingController extends Controller
{
    public function organization(StoreOrganizationOnboardingRequest $request, OnboardingService $service): JsonResponse
    {
        $validated = $request->validated();
        $result = $service->organization(
            $request->user(),
            $request,
            $validated['organization'],
            $validated['administrator']['email'],
        );

        return ApiResponse::success([
            'organization' => (new OrganizationResource($result['organization']))->resolve($request),
            'administrator_invitation' => $this->organizationInvitation($result['invitation']),
        ], 201);
    }

    public function artist(StoreArtistOnboardingRequest $request, Organization $organization, OnboardingService $service): JsonResponse
    {
        Gate::authorize('manageArtists', $organization);
        $validated = $request->validated();
        $result = $service->artist(
            $organization,
            $request->user(),
            $request,
            $validated['artist'],
            $validated['administrator']['email'],
            $validated['relationship_type'] ?? 'managing_label',
            $validated['creator_role'] ?? null,
        );

        return ApiResponse::success([
            'artist' => (new ArtistResource($result['artist']))->resolve($request),
            'relationship' => [
                'id' => $result['relationship']->public_id,
                'organization_id' => $organization->public_id,
                'artist_id' => $result['artist']->public_id,
                'relationship_type' => $result['relationship']->relationship_type,
                'started_at' => $result['relationship']->started_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
            'administrator_invitation' => $this->artistInvitation($result['invitation']),
            'creator_membership' => $result['creator_membership'] === null ? null : [
                'id' => $result['creator_membership']->public_id,
                'role' => $result['creator_membership']->role->value,
                'status' => $result['creator_membership']->status->value,
            ],
        ], 201);
    }

    private function organizationInvitation(OrganizationInvitation $invitation): array
    {
        return [
            'id' => $invitation->public_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function artistInvitation(ArtistInvitation $invitation): array
    {
        return [
            'id' => $invitation->public_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
