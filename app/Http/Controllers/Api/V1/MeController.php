<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class MeController extends Controller
{
    public function __construct(private readonly SecurityAuditLogger $auditLogger) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->resource($request->user()->loadMissing('profile')));
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        $user = $request->user()->load('profile');
        $user->profile()->updateOrCreate([], [
            'display_name' => $request->string('display_name')->toString(),
        ]);
        $this->auditLogger->record('account.profile_updated', $user, $request);

        return ApiResponse::success($this->resource($user->load('profile')));
    }

    public function workspaces(Request $request): JsonResponse
    {
        $user = $request->user();
        $cacheKey = "users:{$user->getKey()}:workspaces:v1";

        return ApiResponse::success([
            'workspaces' => Cache::remember($cacheKey, now()->addSeconds(60), fn (): array => $this->buildWorkspaces($user)),
        ]);
    }

    /** @return array<string, mixed> */
    private function resource(User $user): array
    {
        return [
            'id' => $user->public_id,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'is_superadmin' => (bool) $user->is_superadmin,
            'profile' => [
                'display_name' => $user->profile?->display_name,
            ],
        ];
    }

    /** @return array<int, array<string, string>> */
    private function buildWorkspaces(User $user): array
    {
        $workspaces = collect();

        if ($user->is_superadmin) {
            $workspaces->push([
                'id' => 'platform',
                'type' => 'platform',
                'name' => 'Uncovr',
                'role' => 'superadmin',
                'status' => 'active',
            ]);
        }

        $workspaces->push(...DB::table('organization_memberships')
            ->join('organizations', 'organizations.id', '=', 'organization_memberships.organization_id')
            ->join('organization_profiles', 'organization_profiles.organization_id', '=', 'organizations.id')
            ->where('organization_memberships.user_id', $user->getKey())
            ->select([
                'organizations.public_id as id',
                'organization_profiles.name as name',
                'organization_memberships.role as role',
                'organization_memberships.status as status',
            ])
            ->get()
            ->map(fn (object $membership): array => [
                'id' => $membership->id,
                'type' => 'organization',
                'name' => $membership->name,
                'role' => $membership->role,
                'status' => $membership->status,
            ]));

        $workspaces->push(...DB::table('artist_memberships')
            ->join('artists', 'artists.id', '=', 'artist_memberships.artist_id')
            ->join('artist_profiles', 'artist_profiles.artist_id', '=', 'artists.id')
            ->where('artist_memberships.user_id', $user->getKey())
            ->select([
                'artists.public_id as id',
                'artist_profiles.name as name',
                'artist_memberships.role as role',
                'artist_memberships.status as status',
            ])
            ->get()
            ->map(fn (object $membership): array => [
                'id' => $membership->id,
                'type' => 'artist',
                'name' => $membership->name,
                'role' => $membership->role,
                'status' => $membership->status,
            ]));

        return $workspaces
            ->sortBy(fn (array $workspace): string => implode('|', [
                $workspace['type'],
                mb_strtolower($workspace['name']),
                $workspace['id'],
            ]))
            ->values()
            ->all();
    }
}
