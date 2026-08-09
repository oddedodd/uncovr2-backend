<?php

namespace App\Http\Resources;

use App\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserDetailResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            ...(new UserResource($this->resource))->resolve($request),
            'memberships' => [
                'organizations' => $this->organizationMemberships
                    ->sortBy('public_id')
                    ->values()
                    ->map(fn ($membership): array => [
                        'id' => $membership->public_id,
                        'role' => $membership->role->value,
                        'status' => $membership->status->value,
                        'organization' => [
                            'id' => $membership->organization->public_id,
                            'status' => $membership->organization->status,
                            'name' => $membership->organization->profile->name,
                            'artists' => $membership->organization->artistRelationships
                                ->sortBy('public_id')
                                ->values()
                                ->map(fn ($relationship): array => [
                                    'relationship_id' => $relationship->public_id,
                                    'relationship_type' => $relationship->relationship_type,
                                    'id' => $relationship->artist->public_id,
                                    'status' => $relationship->artist->status,
                                    'name' => $relationship->artist->profile->name,
                                ])->all(),
                            'releases' => $membership->organization->releases
                                ->sortByDesc('public_id')
                                ->values()
                                ->map(fn (Release $release): array => $this->release($release))
                                ->all(),
                        ],
                    ])->all(),
                'artists' => $this->artistMemberships
                    ->sortBy('public_id')
                    ->values()
                    ->map(fn ($membership): array => [
                        'id' => $membership->public_id,
                        'role' => $membership->role->value,
                        'status' => $membership->status->value,
                        'artist' => [
                            'id' => $membership->artist->public_id,
                            'status' => $membership->artist->status,
                            'name' => $membership->artist->profile->name,
                            'organizations' => $membership->artist->organizationRelationships
                                ->sortBy('public_id')
                                ->values()
                                ->map(fn ($relationship): array => [
                                    'relationship_id' => $relationship->public_id,
                                    'relationship_type' => $relationship->relationship_type,
                                    'id' => $relationship->organization->public_id,
                                    'status' => $relationship->organization->status,
                                    'name' => $relationship->organization->profile->name,
                                ])->all(),
                            'releases' => $membership->artist->ownedReleases
                                ->sortByDesc('public_id')
                                ->values()
                                ->map(fn (Release $release): array => $this->release($release))
                                ->all(),
                        ],
                    ])->all(),
            ],
            'release_editor_assignments' => $this->releaseEditorAssignments
                ->sortByDesc(fn ($assignment) => $assignment->release->public_id)
                ->values()
                ->map(fn ($assignment): array => $this->release($assignment->release))
                ->all(),
        ];
    }

    private function release(Release $release): array
    {
        return [
            'id' => $release->public_id,
            'title' => $release->title,
            'type' => $release->type->value,
            'status' => $release->status,
            'release_date' => $release->release_date?->format('Y-m-d'),
        ];
    }
}
