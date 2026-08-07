<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DeviceSession;
use App\Services\Auth\CurrentDeviceSessionResolver;
use App\Services\Auth\DeviceSessionRevocationService;
use App\Services\Auth\SecurityAuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DeviceSessionController extends Controller
{
    public function __construct(
        private readonly CurrentDeviceSessionResolver $sessionResolver,
        private readonly DeviceSessionRevocationService $revocationService,
        private readonly SecurityAuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $currentId = $this->sessionResolver->resolve($request)?->getKey();
        $sessions = $request->user()->deviceSessions()
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (DeviceSession $session): array => $this->resource(
                $session,
                $session->getKey() === $currentId,
            ))
            ->values()
            ->all();

        return ApiResponse::success(['sessions' => $sessions]);
    }

    public function destroy(Request $request, DeviceSession $deviceSession): JsonResponse
    {
        if ($deviceSession->user_id !== $request->user()->getKey()) {
            abort(404);
        }

        $current = $this->sessionResolver->resolve($request);
        $isCurrent = $current?->is($deviceSession) ?? false;
        $this->revocationService->revoke($deviceSession, 'user_session_revoked');
        $this->auditLogger->record(
            'auth.session_revoked',
            $request->user(),
            $request,
            $deviceSession->public_id,
            ['current' => $isCurrent],
        );

        if ($isCurrent && $request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(['message' => 'Session revoked.'])
            ->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function resource(DeviceSession $session, bool $current): array
    {
        return [
            'id' => $session->public_id,
            'client_type' => $session->client_type,
            'device_name' => $session->device_name,
            'platform' => $session->platform,
            'app_version' => $session->app_version,
            'last_ip_address' => $session->last_ip_address,
            'user_agent' => $session->user_agent,
            'last_used_at' => $this->timestamp($session->last_used_at),
            'idle_expires_at' => $this->timestamp($session->idle_expires_at),
            'absolute_expires_at' => $this->timestamp($session->absolute_expires_at),
            'current' => $current,
        ];
    }

    private function timestamp(CarbonInterface $timestamp): string
    {
        return $timestamp->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
