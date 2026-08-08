<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreArtistMembershipRequest;
use App\Http\Requests\Api\V1\UpdateArtistMembershipRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\ArtistMembership;
use App\Models\User;
use App\Services\Auth\SecurityAuditLogger;
use App\Services\Authorization\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ArtistMembershipController extends Controller
{
    public function index(Artist $artist): JsonResponse
    {
        Gate::authorize('manageMembers', $artist);

        return ApiResponse::success($artist->memberships()->with('user.profile')->orderBy('public_id')->get()->map(fn ($membership) => $this->resource($membership))->all());
    }

    public function store(StoreArtistMembershipRequest $request, Artist $artist, SecurityAuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageMembers', $artist);
        $user = User::query()->where('email', strtolower($request->string('email')->toString()))->sole();
        if ($artist->memberships()->where('user_id', $user->getKey())->exists()) {
            throw new ConflictHttpException('User is already an artist member.');
        }
        $membership = $artist->memberships()->create([
            'user_id' => $user->getKey(), 'role' => $request->string('role')->toString(),
            'status' => MembershipStatus::Active->value, 'invited_by_user_id' => $request->user()->getKey(), 'joined_at' => now(),
        ]);
        $audit->record('artist.membership_added', $request->user(), $request, metadata: ['artist_id' => $artist->public_id, 'membership_id' => $membership->public_id]);

        return ApiResponse::success($this->resource($membership), 201);
    }

    public function update(UpdateArtistMembershipRequest $request, Artist $artist, ArtistMembership $membership, MembershipService $service): JsonResponse
    {
        $this->assertParent($artist, $membership);
        Gate::authorize('update', $membership);

        return ApiResponse::success($this->resource($service->updateArtist($membership, $request->validated(), $request->user(), $request)));
    }

    public function destroy(Request $request, Artist $artist, ArtistMembership $membership, MembershipService $service): JsonResponse
    {
        $this->assertParent($artist, $membership);
        Gate::authorize('delete', $membership);
        $service->removeArtist($membership, $request->user(), $request);

        return ApiResponse::success(['message' => 'Membership removed.']);
    }

    private function assertParent(Artist $artist, ArtistMembership $membership): void
    {
        if ($membership->artist_id !== $artist->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function resource(ArtistMembership $membership): array
    {
        $membership->loadMissing('user.profile', 'artist');

        return [
            'id' => $membership->public_id,
            'artist_id' => $membership->artist->public_id,
            'user' => ['id' => $membership->user->public_id, 'email' => $membership->user->email, 'display_name' => $membership->user->profile?->display_name],
            'role' => $membership->role->value,
            'status' => $membership->status->value,
        ];
    }
}
