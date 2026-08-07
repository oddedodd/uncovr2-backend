<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\RefreshTokenRotationService;
use Illuminate\Http\JsonResponse;

final class RefreshTokenController extends Controller
{
    public function __construct(
        private readonly RefreshTokenRotationService $rotationService,
    ) {}

    public function __invoke(RefreshTokenRequest $request): JsonResponse
    {
        $data = $this->rotationService->rotate(
            $request->string('refresh_token')->toString(),
            $request,
        );

        if ($data === null) {
            return ApiResponse::error(
                code: 'invalid_refresh_token',
                message: 'The refresh token is invalid or expired.',
                status: 401,
            )->header('Cache-Control', 'no-store');
        }

        return ApiResponse::success($data)->header('Cache-Control', 'no-store');
    }
}
