<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProtectedScopeIndexRequest;
use App\Http\Requests\Api\V1\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\UpdateOrganizationRequest;
use App\Http\Requests\Api\V1\UpdateScopeStatusRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Services\Api\CursorPagination;
use App\Services\Auth\SecurityAuditLogger;
use App\Services\Organizations\OrganizationService;
use App\Services\PublicApi\PublicCatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class OrganizationController extends Controller
{
    public function index(ProtectedScopeIndexRequest $request, CursorPagination $pagination): JsonResponse
    {
        $query = Organization::query()
            ->with('profile')
            ->orderByDesc('public_id');

        if (! $request->user()->is_superadmin) {
            $query->where('status', 'active')->whereHas('memberships', fn ($memberships) => $memberships
                ->where('user_id', $request->user()->getKey())
                ->where('status', MembershipStatus::Active->value));
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->string('filter.status')->toString());
        }

        if ($request->filled('filter.search')) {
            $pattern = '%'.trim($request->string('filter.search')->toString()).'%';
            $query->where(function ($search) use ($pattern): void {
                $search->whereLike('public_id', $pattern)
                    ->orWhereHas('profile', fn ($profile) => $profile
                        ->whereLike('name', $pattern)
                        ->orWhereLike('legal_name', $pattern));
            });
        }

        $payload = $pagination->paginate(
            $query,
            $request,
            fn (Organization $organization): array => (new OrganizationResource($organization))->resolve($request),
        );

        return response()->json($payload);
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

    public function update(UpdateOrganizationRequest $request, Organization $organization, PublicCatalogCache $cache): JsonResponse
    {
        Gate::authorize('update', $organization);
        $organization->profile()->update($request->validated());
        $cache->invalidate();

        return ApiResponse::success((new OrganizationResource($organization->load('profile')))->resolve());
    }

    public function updateStatus(UpdateScopeStatusRequest $request, Organization $organization, SecurityAuditLogger $audit, PublicCatalogCache $cache): JsonResponse
    {
        Gate::authorize('suspend', $organization);
        $status = $request->string('status')->toString();
        $organization->update(['status' => $status, 'suspended_at' => $status === 'suspended' ? now() : null]);
        $audit->record('organization.status_changed', $request->user(), $request, metadata: ['organization_id' => $organization->public_id, 'status' => $status]);
        $cache->invalidate();

        return ApiResponse::success((new OrganizationResource($organization->load('profile')))->resolve());
    }
}
