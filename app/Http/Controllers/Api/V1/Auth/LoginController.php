<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\LoginService;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class LoginController extends Controller
{
    public function __construct(private readonly LoginService $loginService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->with('profile')
            ->where('email', $request->string('email')->toString())
            ->first();
        $password = $request->string('password')->toString();

        if ($user === null) {
            Hash::make($password);

            return $this->invalidCredentials();
        }

        $provider = Auth::guard('web')->getProvider();

        if (! $provider->validateCredentials($user, ['password' => $password])) {
            return $this->invalidCredentials();
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

        return ApiResponse::success($data)->header('Cache-Control', 'no-store');
    }

    private function invalidCredentials(): JsonResponse
    {
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
