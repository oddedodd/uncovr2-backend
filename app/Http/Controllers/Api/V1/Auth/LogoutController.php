<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\CurrentDeviceSessionResolver;
use App\Services\Auth\DeviceSessionRevocationService;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutController extends Controller
{
    public function __construct(
        private readonly CurrentDeviceSessionResolver $sessionResolver,
        private readonly DeviceSessionRevocationService $revocationService,
        private readonly SecurityAuditLogger $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $session = $this->sessionResolver->resolve($request);

        if ($session !== null) {
            $this->revocationService->revoke($session, 'user_logout');
        } elseif ($request->user()?->currentAccessToken() instanceof PersonalAccessToken) {
            $request->user()->currentAccessToken()->delete();
        }

        $this->auditLogger->recordAfterResponse(
            'auth.logout',
            $request->user(),
            $request,
            $session?->public_id,
        );

        $this->invalidatePortalSession($request);

        return ApiResponse::success(['message' => 'Logged out.'])
            ->header('Cache-Control', 'no-store');
    }

    private function invalidatePortalSession(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
