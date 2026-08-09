<?php

namespace App\Services\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

final class CursorPagination
{
    /**
     * @param  Builder<*>  $query
     * @return array{data: array<int, mixed>, meta: array{pagination: array{per_page: int, next_cursor: ?string, previous_cursor: ?string, has_more: bool}}}
     */
    public function paginate(Builder $query, Request $request, callable $presenter): array
    {
        $encoded = $request->input('page.after', $request->input('page.before'));
        $cursor = is_string($encoded) ? Cursor::fromEncoded($encoded) : null;

        if (is_string($encoded) && $cursor === null) {
            throw ValidationException::withMessages(['page' => ['The pagination cursor is invalid.']]);
        }

        $page = $query->cursorPaginate(
            (int) $request->input('page.size', 25),
            cursor: $cursor,
        );

        return [
            'data' => collect($page->items())->map($presenter)->all(),
            'meta' => ['pagination' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ]],
        ];
    }
}
