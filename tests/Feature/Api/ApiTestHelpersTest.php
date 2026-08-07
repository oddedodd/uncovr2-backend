<?php

namespace Tests\Feature\Api;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiTestHelpersTest extends TestCase
{
    public function test_success_helpers_prefix_the_api_version_and_assert_the_envelope(): void
    {
        $this->assertApiSuccess(
            $this->getApi(),
            data: [
                'service' => 'uncovr',
                'version' => 'v1',
            ],
        );
    }

    public function test_error_helpers_assert_validation_details(): void
    {
        Route::post('/api/v1/testing/helpers', function (Request $request) {
            $request->validate(['name' => ['required']]);

            return ApiResponse::success(null);
        });

        $this->assertApiError(
            $this->postApi('/testing/helpers'),
            status: 422,
            code: 'validation_failed',
            message: 'The submitted data is invalid.',
            details: [
                'fields' => [
                    'name' => ['The name field is required.'],
                ],
            ],
        );
    }

    public function test_all_write_helpers_use_versioned_json_requests(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            Route::$method(
                '/api/v1/testing/'.$method,
                fn () => ApiResponse::success(['method' => $method]),
            );

            $this->assertApiSuccess(
                $this->{$method.'Api'}('/testing/'.$method),
                data: ['method' => $method],
            );
        }
    }
}
