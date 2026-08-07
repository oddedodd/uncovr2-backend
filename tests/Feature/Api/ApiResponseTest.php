<?php

namespace Tests\Feature\Api;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_version_root_uses_the_success_envelope(): void
    {
        $this->getJson('/api/v1')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'service' => 'uncovr',
                    'version' => 'v1',
                ],
            ]);
    }

    public function test_success_responses_can_include_metadata(): void
    {
        Route::get('/api/v1/testing/success', fn () => ApiResponse::success(
            data: [['id' => 'example']],
            meta: ['next_cursor' => 'next-page'],
        ));

        $this->getJson('/api/v1/testing/success')
            ->assertOk()
            ->assertExactJson([
                'data' => [['id' => 'example']],
                'meta' => ['next_cursor' => 'next-page'],
            ]);
    }

    public function test_validation_errors_use_the_error_envelope(): void
    {
        Route::post('/api/v1/testing/validation', function (Request $request) {
            $request->validate(['email' => ['required', 'email']]);

            return ApiResponse::success(null);
        });

        $this->postJson('/api/v1/testing/validation')
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The submitted data is invalid.',
                    'details' => [
                        'fields' => [
                            'email' => ['The email field is required.'],
                        ],
                    ],
                ],
            ]);
    }

    public function test_http_errors_use_safe_stable_messages(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ]);
    }

    public function test_unexpected_errors_do_not_expose_exception_details(): void
    {
        Route::get('/api/v1/testing/error', fn () => throw new RuntimeException('Sensitive details'));

        $this->getJson('/api/v1/testing/error')
            ->assertInternalServerError()
            ->assertExactJson([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An unexpected error occurred.',
                ],
            ])
            ->assertDontSee('Sensitive details');
    }
}
