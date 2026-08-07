<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return ApiResponse::error(
                code: 'validation_failed',
                message: 'The submitted data is invalid.',
                status: 422,
                details: ['fields' => $exception->errors()],
            );
        }

        if ($exception instanceof AuthenticationException) {
            return ApiResponse::error(
                code: 'unauthenticated',
                message: 'Authentication is required.',
                status: 401,
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return ApiResponse::error(
                code: $this->codeForStatus($status),
                message: $this->messageForStatus($status),
                status: $status,
                headers: $exception->getHeaders(),
            );
        }

        return ApiResponse::error(
            code: 'internal_error',
            message: 'An unexpected error occurred.',
            status: 500,
        );
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            410 => 'gone',
            422 => 'unprocessable_entity',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'server_error' : 'request_failed',
        };
    }

    private function messageForStatus(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Authentication is required.',
            403 => 'You are not allowed to perform this action.',
            404 => 'The requested resource was not found.',
            405 => 'The HTTP method is not allowed for this endpoint.',
            409 => 'The request conflicts with the current resource state.',
            410 => 'The requested resource is no longer available.',
            422 => 'The request could not be processed.',
            429 => 'Too many requests. Please try again later.',
            default => $status >= 500
                ? 'An unexpected error occurred.'
                : 'The request failed.',
        };
    }
}
