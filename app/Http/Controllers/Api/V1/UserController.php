<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UserIndexRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Api\CursorPagination;
use Illuminate\Http\JsonResponse;

final class UserController extends Controller
{
    public function index(UserIndexRequest $request, CursorPagination $pagination): JsonResponse
    {
        $query = User::query()
            ->with('profile')
            ->orderByDesc('public_id');

        if ($request->filled('filter.search')) {
            $pattern = '%'.trim($request->string('filter.search')->toString()).'%';
            $query->where(function ($search) use ($pattern): void {
                $search->whereLike('public_id', $pattern)
                    ->orWhereLike('email', $pattern)
                    ->orWhereHas('profile', fn ($profile) => $profile->whereLike('display_name', $pattern));
            });
        }

        $payload = $pagination->paginate(
            $query,
            $request,
            fn (User $user): array => (new UserResource($user))->resolve($request),
        );

        return response()->json($payload);
    }
}
