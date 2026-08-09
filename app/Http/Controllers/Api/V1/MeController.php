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
            'workspaces' => Cache::remember($cacheKey, now()->addSeconds(60), fn (): array => $this->buildWorkspaces(
                $this->loadContext(User::query()->findOrFail($user->getKey())),
            )),
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

    private function loadContext(User $user): User
    {
        return $user->load([
            'profile',
            'organizationMemberships.organization.profile',
            'artistMemberships.artist.profile',
        ]);
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

        $workspaces->push(...$user->organizationMemberships->map(fn ($membership): array => [
            'id' => $membership->organization->public_id,
            'type' => 'organization',
            'name' => $membership->organization->profile->name,
            'role' => $membership->role->value,
            'status' => $membership->status->value,
        ]));

        $workspaces->push(...$user->artistMemberships->map(fn ($membership): array => [
            'id' => $membership->artist->public_id,
            'type' => 'artist',
            'name' => $membership->artist->profile->name,
            'role' => $membership->role->value,
            'status' => $membership->status->value,
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
