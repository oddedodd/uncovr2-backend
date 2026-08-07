<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendVerificationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class ResendVerificationController extends Controller
{
    public function __invoke(ResendVerificationRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return RegisterController::acceptedResponse();
    }
}
