<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LinkOrganizationArtistRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\Organization;
use App\Models\OrganizationArtistRelationship;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrganizationArtistController extends Controller
{
    public function store(LinkOrganizationArtistRequest $request, Organization $organization, SecurityAuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageArtists', $organization);
        $artist = Artist::query()->where('public_id', $request->string('artist_id')->toString())->sole();
        Gate::authorize('manageMembers', $artist);
        if ($organization->artistRelationships()->where('artist_id', $artist->getKey())->whereNull('ended_at')->exists()) {
            throw new ConflictHttpException('Artist is already linked to this organization.');
        }
        $relationship = $organization->artistRelationships()->create([
            'artist_id' => $artist->getKey(),
            'relationship_type' => $request->input('relationship_type', 'managing_label'),
            'created_by_user_id' => $request->user()->getKey(),
            'started_at' => now(),
        ]);
        $audit->record('organization.artist_linked', $request->user(), $request, metadata: ['organization_id' => $organization->public_id, 'artist_id' => $artist->public_id]);

        return ApiResponse::success($this->resource($relationship), 201);
    }

    public function destroy(Request $request, Organization $organization, OrganizationArtistRelationship $relationship, SecurityAuditLogger $audit): JsonResponse
    {
        if ($relationship->organization_id !== $organization->getKey() || $relationship->ended_at) {
            throw new NotFoundHttpException;
        }
        Gate::authorize('delete', $relationship);
        $relationship->update(['ended_at' => now()]);
        $audit->record('organization.artist_unlinked', $request->user(), $request, metadata: ['organization_id' => $organization->public_id, 'artist_id' => $relationship->artist->public_id]);

        return ApiResponse::success(['message' => 'Artist relationship ended.']);
    }

    private function resource(OrganizationArtistRelationship $relationship): array
    {
        $relationship->loadMissing('organization', 'artist');

        return ['id' => $relationship->public_id, 'organization_id' => $relationship->organization->public_id, 'artist_id' => $relationship->artist->public_id, 'relationship_type' => $relationship->relationship_type, 'started_at' => $relationship->started_at->utc()->format('Y-m-d\TH:i:s.v\Z'), 'ended_at' => null];
    }
}
