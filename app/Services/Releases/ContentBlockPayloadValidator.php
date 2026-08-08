<?php

namespace App\Services\Releases;

use App\Models\Media;
use App\Models\Release;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ContentBlockPayloadValidator
{
    public function __construct(private readonly ReleaseScopeResolver $scopeResolver) {}

    public function validate(string $type, array $payload, Release $release): array
    {
        [$rules, $allowed] = match ($type) {
            'heading' => [['text' => ['required', 'string', 'max:500'], 'level' => ['required', 'integer', 'between:1,6']], ['text', 'level']],
            'text' => [['body' => ['required', 'string', 'max:100000']], ['body']],
            'image' => [['media_id' => ['required', 'ulid'], 'alt_text' => ['required', 'string', 'max:500'], 'caption' => ['nullable', 'string', 'max:2000']], ['media_id', 'alt_text', 'caption']],
            'gallery' => [['items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.media_id' => ['required', 'ulid', 'distinct'], 'items.*.alt_text' => ['required', 'string', 'max:500'], 'items.*.caption' => ['nullable', 'string', 'max:2000']], ['items']],
            'video' => [['url' => ['nullable', 'url:https', 'max:2048'], 'media_id' => ['nullable', 'ulid'], 'caption' => ['nullable', 'string', 'max:2000']], ['url', 'media_id', 'caption']],
            'quote' => [['text' => ['required', 'string', 'max:10000'], 'attribution' => ['nullable', 'string', 'max:500']], ['text', 'attribution']],
            'lyrics' => [['text' => ['required', 'string', 'max:100000'], 'language' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/']], ['text', 'language']],
            default => throw ValidationException::withMessages(['type' => ['The selected block type is invalid.']]),
        };

        $errors = [];
        foreach (array_diff(array_keys($payload), $allowed) as $field) {
            $errors["payload.{$field}"][] = "The {$field} field is not allowed.";
        }
        if ($type === 'gallery' && is_array($payload['items'] ?? null)) {
            foreach ($payload['items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (array_diff(array_keys($item), ['media_id', 'alt_text', 'caption']) as $field) {
                    $errors["payload.items.{$index}.{$field}"][] = "The {$field} field is not allowed.";
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $validated = Validator::make($payload, $rules, [], [])->validate();
        if ($type === 'video' && filled($validated['url'] ?? null) === filled($validated['media_id'] ?? null)) {
            throw ValidationException::withMessages(['payload' => ['A video block must contain exactly one of url or media_id.']]);
        }

        $mediaIds = match ($type) {
            'image' => [$validated['media_id']],
            'gallery' => array_column($validated['items'], 'media_id'),
            'video' => isset($validated['media_id']) ? [$validated['media_id']] : [],
            default => [],
        };
        foreach ($mediaIds as $mediaId) {
            $media = Media::query()->where('public_id', $mediaId)->first();
            if (! $media) {
                throw ValidationException::withMessages(['payload.media_id' => ['The selected media is invalid.']]);
            }
            $this->scopeResolver->assertSameOwner($release, $media, 'payload.media_id');
        }

        return $validated;
    }
}
