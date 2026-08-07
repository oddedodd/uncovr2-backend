<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

final class ForgotPasswordController extends Controller
{
    public function __construct(private readonly SecurityAuditLogger $auditLogger) {}

    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        Password::sendResetLink([
            'email' => $request->string('email')->toString(),
        ]);

        if ($user !== null) {
            $this->auditLogger->record('auth.password_reset_requested', $user, $request);
        }

        return ApiResponse::success([
            'message' => 'If the account exists, a password reset email will be sent.',
        ], 202)->header('Cache-Control', 'no-store');
    }
}
