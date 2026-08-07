<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
        ])->header('Cache-Control', 'no-store');
    }

    public function ready(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                code: 'service_unavailable',
                message: 'The service is not ready.',
                status: 503,
            )->header('Cache-Control', 'no-store');
        }

        return ApiResponse::success([
            'status' => 'ready',
        ])->header('Cache-Control', 'no-store');
    }
}
