<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptArtistInvitationRequest;
use App\Http\Requests\Api\V1\InviteArtistMemberRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\ArtistInvitation;
use App\Services\Artists\ArtistInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ArtistInvitationController extends Controller
{
    public function store(InviteArtistMemberRequest $request, Artist $artist, ArtistInvitationService $service): JsonResponse
    {
        Gate::authorize('manageMembers', $artist);
        $invitation = $service->create(
            $artist,
            $request->user(),
            $request,
            $request->string('email')->toString(),
            $request->string('role')->toString(),
        );

        return ApiResponse::success($this->resource($invitation), 201);
    }

    public function resend(Request $request, ArtistInvitation $invitation, ArtistInvitationService $service): JsonResponse
    {
        Gate::authorize('manageMembers', $invitation->artist);

        return ApiResponse::success($this->resource($service->resend($invitation, $request->user(), $request)));
    }

    public function accept(AcceptArtistInvitationRequest $request, ArtistInvitationService $service): JsonResponse
    {
        $membership = $service->accept($request->string('token')->toString(), $request->user(), $request);

        return ApiResponse::success([
            'membership_id' => $membership->public_id,
            'artist_id' => $membership->artist->public_id,
            'role' => $membership->role->value,
        ]);
    }

    public static function resource(ArtistInvitation $invitation): array
    {
        return [
            'id' => $invitation->public_id,
            'artist_id' => $invitation->artist->public_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'last_sent_at' => $invitation->last_sent_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'send_count' => $invitation->send_count,
        ];
    }
}
