<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\UpdateOrganizationRequest;
use App\Http\Requests\Api\V1\UpdateScopeStatusRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Services\Auth\SecurityAuditLogger;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()->with('profile')->orderBy('public_id');
        if (! $request->user()->is_superadmin) {
            $query->where('status', 'active')->whereHas('memberships', fn ($memberships) => $memberships
                ->where('user_id', $request->user()->getKey())
                ->where('status', MembershipStatus::Active->value));
        }

        return ApiResponse::success($query->get()->map(fn ($item) => (new OrganizationResource($item))->resolve())->all());
    }

    public function store(StoreOrganizationRequest $request, OrganizationService $service): JsonResponse
    {
        $organization = $service->create($request->user(), $request->validated());

        return ApiResponse::success((new OrganizationResource($organization))->resolve(), 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success((new OrganizationResource($organization->load('profile')))->resolve());
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('update', $organization);
        $organization->profile()->update($request->validated());

        return ApiResponse::success((new OrganizationResource($organization->load('profile')))->resolve());
    }

    public function updateStatus(UpdateScopeStatusRequest $request, Organization $organization, SecurityAuditLogger $audit): JsonResponse
    {
        Gate::authorize('suspend', $organization);
        $status = $request->string('status')->toString();
        $organization->update(['status' => $status, 'suspended_at' => $status === 'suspended' ? now() : null]);
        $audit->record('organization.status_changed', $request->user(), $request, metadata: ['organization_id' => $organization->public_id, 'status' => $status]);

        return ApiResponse::success((new OrganizationResource($organization->load('profile')))->resolve());
    }
}
