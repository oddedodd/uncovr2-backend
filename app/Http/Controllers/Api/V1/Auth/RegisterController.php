<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = null;

        try {
            $user = DB::transaction(function () use ($request, $email): ?User {
                if (User::query()->where('email', $email)->exists()) {
                    return null;
                }

                $user = User::query()->create([
                    'email' => $email,
                    'password' => $request->string('password')->toString(),
                ]);

                $user->profile()->create([
                    'display_name' => $request->string('display_name')->toString(),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! User::query()->where('email', $email)->exists()) {
                throw $exception;
            }
        }

        if ($user !== null) {
            event(new Registered($user));
        }

        return self::acceptedResponse();
    }

    public static function acceptedResponse(): JsonResponse
    {
        return ApiResponse::success([
            'message' => 'If the address can be registered, a verification email will be sent.',
        ], 202)->header('Cache-Control', 'no-store');
    }
}
