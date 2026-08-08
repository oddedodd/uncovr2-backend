<?php

namespace App\Services\Releases;

use App\Models\ContentBlock;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ContentBlockService
{
    public function __construct(private readonly ContentBlockPayloadValidator $validator, private readonly ReleaseActivityLogger $activity) {}

    public function create(Page $page, User $actor, array $data): ContentBlock
    {
        $release = $page->owningRelease();
        $payload = $this->validator->validate($data['type'], $data['payload'], $release);

        return DB::transaction(function () use ($page, $actor, $data, $payload, $release): ContentBlock {
            Page::query()->lockForUpdate()->findOrFail($page->getKey());
            $this->assertPositionAvailable($page, $data['position']);
            $block = $page->blocks()->create([
                'position' => $data['position'], 'type' => $data['type'], 'payload' => $payload,
                'created_by_user_id' => $actor->getKey(), 'updated_by_user_id' => $actor->getKey(),
            ]);
            $this->snapshot($block, $actor);
            $this->activity->record($release, $actor, 'content_block.created', $block);

            return $block;
        });
    }

    public function update(ContentBlock $block, User $actor, array $data): ContentBlock
    {
        return DB::transaction(function () use ($block, $actor, $data): ContentBlock {
            $locked = ContentBlock::query()->lockForUpdate()->findOrFail($block->getKey());
            $page = $locked->page;
            $release = $page->owningRelease();
            $type = $data['type'] ?? $locked->type->value;
            $payload = $data['payload'] ?? $locked->payload;
            $payload = $this->validator->validate($type, $payload, $release);
            if (isset($data['position']) && $data['position'] !== $locked->position) {
                $this->assertPositionAvailable($page, $data['position'], $locked);
            }
            $locked->update([
                'position' => $data['position'] ?? $locked->position,
                'type' => $type, 'payload' => $payload, 'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->getKey(),
            ]);
            $this->snapshot($locked, $actor);
            $this->activity->record($release, $actor, 'content_block.updated', $locked, ['version' => $locked->version]);

            return $locked;
        });
    }

    private function snapshot(ContentBlock $block, User $actor): void
    {
        $block->versions()->create(['version' => $block->version, 'type' => $block->type->value, 'payload' => $block->payload, 'created_by_user_id' => $actor->getKey(), 'created_at' => now()]);
    }

    private function assertPositionAvailable(Page $page, int $position, ?ContentBlock $except = null): void
    {
        $query = $page->blocks()->where('position', $position);
        if ($except) {
            $query->whereKeyNot($except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['position' => ['The position is already in use on this page.']]);
        }
    }
}
