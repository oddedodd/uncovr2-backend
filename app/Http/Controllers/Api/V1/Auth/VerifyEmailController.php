<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class VerifyEmailController extends Controller
{
    public function __invoke(User $user, int $version, string $hash): JsonResponse
    {
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new AccessDeniedHttpException;
        }

        if ($user->hasVerifiedEmail() || $version !== $user->email_verification_version) {
            throw new GoneHttpException;
        }

        if (! $user->markEmailAsVerified()) {
            throw new GoneHttpException;
        }

        event(new Verified($user));

        return ApiResponse::success([
            'message' => 'Email address verified.',
        ])->header('Cache-Control', 'no-store');
    }
}
