<?php

namespace App\Services\Authorization;

use App\Enums\ReleaseStatus;
use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Projects ReleasePolicy decisions into a serializable capability block so the
 * portal can render the right controls without reimplementing the policy.
 *
 * The flags express role capability, not state-machine validity: can_submit
 * means the user is allowed to submit, never that the release is complete
 * enough to pass validation. Services still reject illegal transitions.
 *
 * ReleaseAuthorizationTest asserts every flag equals the matching Gate check,
 * which is what keeps this in step with ReleasePolicy.
 */
final class ReleaseCapabilities
{
    public function __construct(private readonly ScopeAccess $access) {}

    /** @return array<string, bool> */
    public function forRelease(User $user, Release $release): array
    {
        if ($user->is_superadmin) {
            return $this->all(true);
        }

        // Callers reach this after Gate::authorize(), so the ScopeAccess cache is
        // already warm and the loaded editorAssignments relation avoids a query.
        $isEditor = $release->relationLoaded('editorAssignments')
            ? $release->editorAssignments->contains(fn ($assignment): bool => $assignment->user_id === $user->getKey())
            : $release->editorAssignments()->where('user_id', $user->getKey())->exists();

        $canManage = $release->organization_id
            ? $this->access->canManageOrganization($user, $release->organization)
            : $this->access->canManageArtist($user, $release->ownerArtist);

        return $this->derive($canManage, $isEditor, $release->status);
    }

    /**
     * Listing variant. Runs at most one query in total regardless of page size.
     *
     * Load-bearing assumption: every release on the page is already viewable by
     * the user, because ReleaseController@index applies the same owner
     * disjunction as ReleasePolicy::canViewOwner. Only the manage/editor halves
     * remain to be resolved here.
     *
     * @param  Collection<int, Release>  $releases
     * @param  array<int, array<int, array{user_id: string, display_name: string|null}>>  $editorsByRelease
     * @return array<int, array<string, bool>> keyed by release primary key
     */
    public function forListing(User $user, Collection $releases, array $editorsByRelease): array
    {
        if ($releases->isEmpty()) {
            return [];
        }

        if ($user->is_superadmin) {
            return $releases
                ->mapWithKeys(fn (Release $release): array => [$release->getKey() => $this->all(true)])
                ->all();
        }

        $manageable = $this->access->manageableOwners(
            $user,
            $releases->pluck('organization_id')->filter()->map(fn ($key): int => (int) $key)->all(),
            $releases->pluck('artist_id')->filter()->map(fn ($key): int => (int) $key)->all(),
        );

        return $releases
            ->mapWithKeys(function (Release $release) use ($user, $manageable, $editorsByRelease): array {
                $canManage = $release->organization_id
                    ? isset($manageable['organizations'][(int) $release->organization_id])
                    : isset($manageable['artists'][(int) $release->artist_id]);
                $editors = $editorsByRelease[$release->getKey()] ?? [];
                $isEditor = in_array($user->public_id, array_column($editors, 'user_id'), true);

                return [$release->getKey() => $this->derive($canManage, $isEditor, $release->status)];
            })
            ->all();
    }

    /** @return array<string, bool> */
    private function derive(bool $canManage, bool $isEditor, string $status): array
    {
        $editable = in_array($status, [ReleaseStatus::Draft->value, ReleaseStatus::Unpublished->value], true);
        $canUpdate = $editable && ($canManage || $isEditor);

        return [
            'can_update' => $canUpdate,
            'can_submit' => $canUpdate,
            'can_delete' => $canUpdate && $status === ReleaseStatus::Draft->value,
            'can_approve' => $canManage,
            'can_publish' => $canManage,
            'can_manage_editors' => $canManage,
        ];
    }

    /** @return array<string, bool> */
    private function all(bool $value): array
    {
        return [
            'can_update' => $value,
            'can_submit' => $value,
            'can_delete' => $value,
            'can_approve' => $value,
            'can_publish' => $value,
            'can_manage_editors' => $value,
        ];
    }
}
