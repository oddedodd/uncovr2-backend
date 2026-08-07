<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function __construct(private readonly SecurityAuditLogger $auditLogger) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->resource($request->user()->load('profile')));
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

    /** @return array<string, mixed> */
    private function resource($user): array
    {
        return [
            'id' => $user->public_id,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'profile' => [
                'display_name' => $user->profile?->display_name,
            ],
        ];
    }
}
