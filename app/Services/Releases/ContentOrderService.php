<?php

namespace App\Services\Releases;

use App\Models\ContentBlock;
use App\Models\Page;
use App\Models\Release;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ordering is owned by the backend. Callers describe the order they want and the
 * service renumbers the whole sibling set to a contiguous 1..n, so a client never
 * has to reason about the positions of the rows it is not moving.
 *
 * The partial unique indexes on (release_id, position), (track_id, position) and
 * (page_id, position) are immediate — a partial index cannot be deferred — so a
 * renumbering cannot walk the rows one by one through the final range. Every
 * renumbering therefore runs as a two-phase write inside one transaction: park
 * the rows above the current maximum, then write the final positions.
 */
final class ContentOrderService
{
    public function __construct(private readonly ReleaseActivityLogger $activity) {}

    /**
     * @param  list<string>  $publicIds
     * @return Collection<int, Page>
     */
    public function reorderPages(Release $release, User $actor, array $publicIds): Collection
    {
        return DB::transaction(function () use ($release, $actor, $publicIds): Collection {
            $pages = $this->arrange(
                $release->pages(),
                $publicIds,
                'page_ids',
                'The order must list every page in the release exactly once.',
                $actor,
            );
            $this->activity->record($release, $actor, 'page.reordered', null, ['page_ids' => $pages->pluck('public_id')->all()]);

            return $pages;
        });
    }

    /**
     * @param  list<string>  $publicIds
     * @return Collection<int, ContentBlock>
     */
    public function reorderBlocks(Page $page, User $actor, array $publicIds): Collection
    {
        return DB::transaction(function () use ($page, $actor, $publicIds): Collection {
            $blocks = $this->arrange(
                $page->blocks(),
                $publicIds,
                'block_ids',
                'The order must list every block on the page exactly once.',
                $actor,
            );
            $this->activity->record($page->owningRelease(), $actor, 'content_block.reordered', $page, ['block_ids' => $blocks->pluck('public_id')->all()]);

            return $blocks;
        });
    }

    /**
     * Move a single page to an absolute position and close the gap it leaves.
     * The position is clamped to the sibling count, so "move to 99" means last
     * rather than a validation error.
     */
    public function movePage(Page $page, User $actor, int $position): void
    {
        $this->move($page->release_id ? $page->release->pages() : $page->track->pages(), $page, $actor, $position);
    }

    public function moveBlock(ContentBlock $block, User $actor, int $position): void
    {
        $this->move($block->page->blocks(), $block, $actor, $position);
    }

    /**
     * @param  HasMany<Model, Model>  $relation
     * @param  list<string>  $publicIds
     * @return Collection<int, Model>
     */
    private function arrange(HasMany $relation, array $publicIds, string $field, string $message, User $actor): Collection
    {
        $siblings = $this->lock($relation);
        $byPublicId = $siblings->keyBy('public_id');

        $ordered = new Collection;
        foreach ($publicIds as $publicId) {
            $sibling = $byPublicId->get($publicId);
            if (! $sibling instanceof Model) {
                throw ValidationException::withMessages([$field => [$message]]);
            }
            $ordered->push($sibling);
        }

        // The `distinct` rule rejects repeats, so an equal count means the client
        // sent a complete permutation rather than a partial reorder.
        if ($ordered->count() !== $siblings->count()) {
            throw ValidationException::withMessages([$field => [$message]]);
        }

        $this->renumber($ordered, $actor);

        return $ordered;
    }

    /**
     * @param  HasMany<Model, Model>  $relation
     */
    private function move(HasMany $relation, Model $model, User $actor, int $position): void
    {
        DB::transaction(function () use ($relation, $model, $actor, $position): void {
            $ordered = $this->lock($relation);
            $current = $ordered->search(fn (Model $sibling): bool => $sibling->getKey() === $model->getKey());
            if ($current === false) {
                return;
            }

            $target = max(1, min($position, $ordered->count())) - 1;
            $moved = $ordered->splice($current, 1)->first();
            $ordered->splice($target, 0, [$moved]);

            $this->renumber($ordered->values(), $actor);
            $model->setAttribute('position', $target + 1)->syncOriginalAttribute('position');
        });
    }

    /**
     * @param  HasMany<Model, Model>  $relation
     * @return Collection<int, Model>
     */
    private function lock(HasMany $relation): Collection
    {
        return $relation->lockForUpdate()->get()->values();
    }

    /**
     * @param  Collection<int, Model>  $ordered
     */
    private function renumber(Collection $ordered, User $actor): void
    {
        if ($ordered->every(fn (Model $model, int $index): bool => $model->position === $index + 1)) {
            return;
        }

        // Park above the current maximum. Positions are distinct and >= 1, so the
        // maximum is at least the row count and no parked value can collide with
        // the final 1..n range.
        $parking = (int) $ordered->max('position');
        foreach ($ordered as $index => $model) {
            $model->newQuery()->whereKey($model->getKey())->update(['position' => $parking + $index + 1]);
        }

        foreach ($ordered as $index => $model) {
            $model->newQuery()->whereKey($model->getKey())->update([
                'position' => $index + 1,
                'updated_by_user_id' => $actor->getKey(),
            ]);
            $model->setAttribute('position', $index + 1)->syncOriginalAttribute('position');
        }
    }
}
