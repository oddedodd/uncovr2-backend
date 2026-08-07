<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\DeviceSessionRevocationService;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class ResetPasswordController extends Controller
{
    public function __construct(
        private readonly DeviceSessionRevocationService $revocationService,
        private readonly SecurityAuditLogger $auditLogger,
    ) {}

    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request): void {
                DB::transaction(function () use ($user, $password, $request): void {
                    $user->forceFill(['password' => $password])
                        ->setRememberToken(Str::random(60));
                    $user->save();

                    $this->revocationService->revokeAll($user, 'password_reset');
                    $this->auditLogger->record('auth.password_reset_completed', $user, $request);
                }, attempts: 3);
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            return ApiResponse::error(
                code: 'invalid_password_reset',
                message: 'The password reset request is invalid or expired.',
                status: 422,
            )->header('Cache-Control', 'no-store');
        }

        return ApiResponse::success([
            'message' => 'Password reset. Sign in again on your devices.',
        ])->header('Cache-Control', 'no-store');
    }
}
