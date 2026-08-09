<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class LoginController extends Controller
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly SecurityAuditLogger $auditLogger,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->with('profile')
            ->where('email', $request->string('email')->toString())
            ->first();
        $password = $request->string('password')->toString();

        if ($user === null) {
            Hash::make($password);

            return $this->invalidCredentials($request);
        }

        $provider = Auth::guard('web')->getProvider();

        if (! $provider->validateCredentials($user, ['password' => $password])) {
            return $this->invalidCredentials($request, $user);
        }

        if ($user->status === UserStatus::Suspended) {
            $this->auditLogger->record(
                'auth.login_blocked_suspended',
                $user,
                $request,
                metadata: ['client_type' => $request->string('client_type')->toString()],
            );

            return ApiResponse::error(
                code: 'account_suspended',
                message: 'This account is suspended. Contact support for assistance.',
                status: 403,
            )->header('Cache-Control', 'no-store');
        }

        $this->rehashPasswordIfRequired($provider, $user, $password);

        if (! $user->hasVerifiedEmail()) {
            return ApiResponse::error(
                code: 'email_not_verified',
                message: 'Verify the email address before signing in.',
                status: 403,
            )->header('Cache-Control', 'no-store');
        }

        if ($request->string('client_type')->toString() === 'portal') {
            if (! $request->hasSession()) {
                return ApiResponse::error(
                    code: 'stateful_session_required',
                    message: 'Portal login requires a stateful browser request.',
                    status: 400,
                )->header('Cache-Control', 'no-store');
            }

            $data = $this->loginService->portal($user, $request, $request->array('device'));
        } else {
            $data = $this->loginService->mobile($user, $request, $request->array('device'));
        }

        $this->auditLogger->record(
            'auth.login_succeeded',
            $user,
            $request,
            $data['session']['id'],
            ['client_type' => $request->string('client_type')->toString()],
        );

        return ApiResponse::success($data)->header('Cache-Control', 'no-store');
    }

    private function invalidCredentials(LoginRequest $request, ?User $user = null): JsonResponse
    {
        $this->auditLogger->record('auth.login_failed', $user, $request);

        return ApiResponse::error(
            code: 'invalid_credentials',
            message: 'The provided credentials are incorrect.',
            status: 401,
        )->header('Cache-Control', 'no-store');
    }

    private function rehashPasswordIfRequired(
        UserProvider $provider,
        User $user,
        string $password,
    ): void {
        $provider->rehashPasswordIfRequired($user, ['password' => $password]);
    }
}
