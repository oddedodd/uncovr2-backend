<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

trait InteractsWithApi
{
    /**
     * @param  array<string, string>  $headers
     */
    protected function getApi(string $path = '', array $headers = []): TestResponse
    {
        return $this->getJson($this->apiUrl($path), $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function postApi(string $path, array $data = [], array $headers = []): TestResponse
    {
        return $this->postJson($this->apiUrl($path), $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function putApi(string $path, array $data = [], array $headers = []): TestResponse
    {
        return $this->putJson($this->apiUrl($path), $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function patchApi(string $path, array $data = [], array $headers = []): TestResponse
    {
        return $this->patchJson($this->apiUrl($path), $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function deleteApi(string $path, array $data = [], array $headers = []): TestResponse
    {
        return $this->deleteJson($this->apiUrl($path), $data, $headers);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    protected function assertApiSuccess(
        TestResponse $response,
        mixed $data,
        int $status = 200,
        ?array $meta = null,
    ): TestResponse {
        $payload = ['data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return $response
            ->assertStatus($status)
            ->assertHeader('X-Request-ID')
            ->assertExactJson($payload);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    protected function assertApiError(
        TestResponse $response,
        int $status,
        string $code,
        ?string $message = null,
        ?array $details = null,
    ): TestResponse {
        $response
            ->assertStatus($status)
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('error.code', $code)
            ->assertJsonMissingPath('data');

        if ($message !== null) {
            $response->assertJsonPath('error.message', $message);
        }

        if ($details !== null) {
            $response->assertJsonPath('error.details', $details);
        }

        return $response;
    }

    protected function apiUrl(string $path = ''): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/api/v1';
        }

        return '/api/v1/'.ltrim($path, '/');
    }
}
