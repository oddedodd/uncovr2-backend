<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\DeviceSessionRevocationService;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LogoutAllController extends Controller
{
    public function __construct(
        private readonly DeviceSessionRevocationService $revocationService,
        private readonly SecurityAuditLogger $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->revocationService->revokeAll($request->user(), 'user_logout_all');
        $this->auditLogger->record('auth.logout_all', $request->user(), $request);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(['message' => 'Logged out from all devices.'])
            ->header('Cache-Control', 'no-store');
    }
}
