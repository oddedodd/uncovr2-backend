<?php

namespace App\Services\Releases;

use App\Contracts\MediaStorage;
use App\Enums\ReleaseStatus;
use App\Models\Release;
use App\Models\ReleaseApprovalRequest;
use App\Models\User;
use App\Services\PublicApi\PublicCatalogCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReleasePublicationService
{
    public function __construct(
        private readonly ReleaseSnapshotBuilder $snapshots,
        private readonly ReleaseActivityLogger $activity,
        private readonly MediaStorage $storage,
        private readonly PublicCatalogCache $publicCache,
    ) {}

    public function preview(Release $release): array
    {
        $errors = $this->readinessErrors($release);

        return ['ready_for_review' => $errors === [], 'errors' => $errors, 'release' => $this->snapshots->build($release)];
    }

    public function submit(Release $release, User $actor, ?string $note): ReleaseApprovalRequest
    {
        $errors = $this->readinessErrors($release);
        if ($errors !== []) {
            throw ValidationException::withMessages(['release' => $errors]);
        }

        return DB::transaction(function () use ($release, $actor, $note): ReleaseApprovalRequest {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            if (! in_array($locked->status, [ReleaseStatus::Draft->value, ReleaseStatus::Unpublished->value], true)) {
                throw ValidationException::withMessages(['status' => ['Only editable releases can be submitted for review.']]);
            }
            $locked->approvalRequests()->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
            $fingerprint = $this->snapshots->fingerprint($locked);
            $request = $locked->approvalRequests()->create([
                'requested_by_user_id' => $actor->getKey(), 'content_fingerprint' => $fingerprint,
                'request_note' => $note,
            ]);
            $locked->update([
                'status' => ReleaseStatus::Review->value, 'submitted_at' => now(),
                'approved_at' => null, 'approved_by_user_id' => null, 'approved_fingerprint' => null,
                'scheduled_for' => null,
            ]);
            $this->activity->record($locked, $actor, 'release.review_submitted', $request, ['fingerprint' => $fingerprint]);

            return $request;
        });
    }

    public function decide(Release $release, User $actor, bool $approve, ?string $note): Release
    {
        return DB::transaction(function () use ($release, $actor, $approve, $note): Release {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            $request = $locked->approvalRequests()->where('status', 'pending')->lockForUpdate()->latest('id')->first();
            if ($locked->status !== ReleaseStatus::Review->value || ! $request) {
                throw ValidationException::withMessages(['status' => ['The release has no pending approval request.']]);
            }
            $current = $this->snapshots->fingerprint($locked);
            if (! hash_equals($request->content_fingerprint, $current)) {
                throw ValidationException::withMessages(['release' => ['The release changed after submission and must be submitted again.']]);
            }
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $actor->getKey(), 'decision_note' => $note, 'decided_at' => now()]);
            $locked->update($approve ? [
                'approved_at' => now(), 'approved_by_user_id' => $actor->getKey(), 'approved_fingerprint' => $current,
            ] : [
                'status' => ReleaseStatus::Draft->value, 'approved_at' => null, 'approved_by_user_id' => null, 'approved_fingerprint' => null,
            ]);
            $this->activity->record($locked, $actor, $approve ? 'release.approved' : 'release.rejected', $request, ['note' => $note]);

            return $locked->fresh();
        });
    }

    public function schedule(Release $release, User $actor, Carbon $publishAt): Release
    {
        return DB::transaction(function () use ($release, $actor, $publishAt): Release {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            $this->assertApproved($locked);
            $locked->update(['status' => ReleaseStatus::Scheduled->value, 'scheduled_for' => $publishAt, 'published_by_user_id' => $actor->getKey()]);
            $this->activity->record($locked, $actor, 'release.scheduled', $locked, ['publish_at' => $publishAt->utc()->toISOString()]);

            return $locked->fresh();
        });
    }

    public function publish(Release $release, User $actor): Release
    {
        $release->refresh();
        $this->assertApproved($release);
        if (! in_array($release->status, [ReleaseStatus::Review->value, ReleaseStatus::Scheduled->value], true)) {
            throw ValidationException::withMessages(['status' => ['The release cannot be published from its current state.']]);
        }
        if ($release->status === ReleaseStatus::Scheduled->value && $release->scheduled_for?->isFuture()) {
            throw ValidationException::withMessages(['scheduled_for' => ['The scheduled publication time has not arrived.']]);
        }
        $version = $release->publication_version + 1;
        $mediaUrls = $this->promoteMedia($release, $version);
        $publishedAt = now();
        $snapshot = $this->snapshots->build($release);
        $snapshot['status'] = ReleaseStatus::Published->value;
        $snapshot['lifecycle']['published_at'] = $publishedAt->utc()->format('Y-m-d\TH:i:s.v\Z');
        $snapshot['lifecycle']['publication_version'] = $version;
        $snapshot['media'] = $mediaUrls;

        $published = DB::transaction(function () use ($release, $actor, $version, $snapshot, $publishedAt): Release {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            $this->assertApproved($locked);
            $locked->publications()->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
            $publication = $locked->publications()->create([
                'version' => $version, 'content_fingerprint' => $locked->approved_fingerprint,
                'title' => $snapshot['title'], 'subtitle' => $snapshot['subtitle'],
                'primary_artist_name' => collect($snapshot['artists'])->firstWhere('is_primary', true)['name'] ?? null,
                'release_type' => $snapshot['type'], 'release_date' => $snapshot['release_date'],
                'cover_url' => $snapshot['media'][$snapshot['cover_media_id']] ?? null,
                'search_text' => trim(implode(' ', array_filter([
                    $snapshot['title'], $snapshot['subtitle'],
                    collect($snapshot['artists'])->pluck('name')->implode(' '),
                    collect($snapshot['tracks'])->pluck('title')->implode(' '),
                ]))),
                'snapshot' => $snapshot, 'published_by_user_id' => $actor->getKey(), 'published_at' => $publishedAt,
            ]);
            $publication->tracks()->createMany(collect($snapshot['tracks'])->map(fn (array $track) => [
                'track_public_id' => $track['id'], 'position' => $track['position'], 'title' => $track['title'],
                'duration_ms' => $track['duration_ms'], 'snapshot' => $track,
            ])->all());
            $locked->update([
                'status' => ReleaseStatus::Published->value, 'publication_version' => $version,
                'published_at' => $publishedAt, 'published_by_user_id' => $actor->getKey(),
                'scheduled_for' => null, 'unpublished_at' => null,
            ]);
            $this->activity->record($locked, $actor, 'release.published', $publication, ['version' => $version]);

            return $locked->fresh();
        });
        $this->publicCache->invalidate();

        return $published;
    }

    public function unpublish(Release $release, User $actor): Release
    {
        $unpublished = DB::transaction(function () use ($release, $actor): Release {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            if ($locked->status !== ReleaseStatus::Published->value) {
                throw ValidationException::withMessages(['status' => ['Only a published release can be unpublished.']]);
            }
            $locked->publications()->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
            $locked->update(['status' => ReleaseStatus::Unpublished->value, 'unpublished_at' => now()]);
            $this->activity->record($locked, $actor, 'release.unpublished', $locked);

            return $locked->fresh();
        });
        $this->publicCache->invalidate();

        return $unpublished;
    }

    public function feature(Release $release, User $actor, bool $featured): Release
    {
        $updated = DB::transaction(function () use ($release, $actor, $featured): Release {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            if ($locked->status !== ReleaseStatus::Published->value) {
                throw ValidationException::withMessages(['release' => ['Only a published release can be featured.']]);
            }
            $locked->update(['featured_at' => $featured ? now() : null]);
            $this->activity->record($locked, $actor, $featured ? 'release.featured' : 'release.unfeatured', $locked);

            return $locked->fresh();
        });
        $this->publicCache->invalidate();

        return $updated;
    }

    public function archive(Release $release, User $actor): Release
    {
        return DB::transaction(function () use ($release, $actor): Release {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            if (! in_array($locked->status, [ReleaseStatus::Draft->value, ReleaseStatus::Unpublished->value], true)) {
                throw ValidationException::withMessages(['status' => ['Only a draft or unpublished release can be archived.']]);
            }
            $locked->update(['status' => ReleaseStatus::Archived->value, 'archived_at' => now()]);
            $this->activity->record($locked, $actor, 'release.archived', $locked);

            return $locked->fresh();
        });
    }

    private function assertApproved(Release $release): void
    {
        if (! $release->approved_at || ! $release->approved_fingerprint || ! hash_equals($release->approved_fingerprint, $this->snapshots->fingerprint($release))) {
            throw ValidationException::withMessages(['approval' => ['A current approval is required.']]);
        }
    }

    private function readinessErrors(Release $release): array
    {
        $release->loadMissing(['artistLinks', 'coverMedia']);
        $errors = [];
        if ($release->artistLinks->where('is_primary', true)->count() !== 1) {
            $errors[] = 'A primary artist is required.';
        }
        if (! $release->coverMedia || $release->coverMedia->status !== 'ready') {
            $errors[] = 'A verified cover image is required.';
        } elseif ($release->coverMedia->kind !== 'image') {
            $errors[] = 'The release cover must be an image.';
        }
        if (! $release->title) {
            $errors[] = 'A title is required.';
        }
        foreach ($this->snapshots->referencedMedia($release) as $media) {
            if ($media->status !== 'ready') {
                $errors[] = "Referenced media {$media->public_id} is not verified.";
            }
        }

        return $errors;
    }

    private function promoteMedia(Release $release, int $version): array
    {
        $urls = [];
        foreach ($this->snapshots->referencedMedia($release) as $media) {
            if ($media->status !== 'ready' || ! $media->storage_disk || ! $media->storage_key) {
                throw ValidationException::withMessages(['media' => ["Media {$media->public_id} is not ready for publication."]]);
            }
            $destination = "releases/{$release->public_id}/v{$version}/{$media->public_id}/".basename($media->storage_key);
            $publicBucket = config('media.public_bucket');
            $this->storage->copy($media->storage_disk, $media->storage_key, $publicBucket, $destination);
            $urls[$media->public_id] = $this->storage->publicUrl($publicBucket, $destination);
        }

        return $urls;
    }
}
