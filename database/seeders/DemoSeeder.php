<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Organization;
use App\Models\Release;
use App\Models\ReleasePublication;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    private const IDS = [
        'superadmin' => '01J00000000000000000000001',
        'label_user' => '01J00000000000000000000002',
        'artist_user' => '01J00000000000000000000003',
        'label' => '01J00000000000000000000004',
        'label_membership' => '01J00000000000000000000005',
        'artist' => '01J00000000000000000000006',
        'artist_membership' => '01J00000000000000000000007',
        'relationship' => '01J00000000000000000000008',
        'release' => '01J00000000000000000000009',
        'track' => '01J0000000000000000000000A',
        'publication' => '01J0000000000000000000000B',
    ];

    public function run(): void
    {
        $password = (string) config('demo.password');
        if (app()->isProduction() && (! config('demo.enabled') || mb_strlen($password) < 12)) {
            throw new RuntimeException('Production demo seeding requires DEMO_SEED_ENABLED=true and a password of at least 12 characters.');
        }
        if (mb_strlen($password) < 12) {
            throw new RuntimeException('DEMO_SEED_PASSWORD must contain at least 12 characters.');
        }

        DB::transaction(function () use ($password): void {
            $superadmin = $this->user('superadmin', 'admin@demo.uncovr.test', 'Demo Superadmin', $password, true);
            $labelUser = $this->user('label_user', 'label@demo.uncovr.test', 'Demo Label Admin', $password);
            $artistUser = $this->user('artist_user', 'artist@demo.uncovr.test', 'Demo Artist', $password);

            $label = Organization::query()->firstOrNew(['public_id' => self::IDS['label']]);
            $label->forceFill(['created_by_user_id' => $labelUser->id, 'status' => 'active'])->save();
            $label->profile()->updateOrCreate([], [
                'name' => 'North Star Records',
                'legal_name' => 'North Star Records Demo AS',
                'description' => 'Deterministic demo label for release verification.',
                'website_url' => 'https://example.test/north-star-records',
            ]);
            $membership = $label->memberships()->firstOrNew(['user_id' => $labelUser->id]);
            $membership->forceFill([
                'public_id' => self::IDS['label_membership'], 'role' => 'label_admin',
                'status' => 'active', 'joined_at' => '2026-01-01 12:00:00+00:00',
            ])->save();

            $artist = Artist::query()->firstOrNew(['public_id' => self::IDS['artist']]);
            $artist->forceFill(['created_by_user_id' => $artistUser->id, 'status' => 'active'])->save();
            $artist->profile()->updateOrCreate([], [
                'name' => 'Aurora Lines',
                'biography' => 'Deterministic demo artist for Uncovr.',
                'website_url' => 'https://example.test/aurora-lines',
            ]);
            $artistMembership = $artist->memberships()->firstOrNew(['user_id' => $artistUser->id]);
            $artistMembership->forceFill([
                'public_id' => self::IDS['artist_membership'], 'role' => 'artist_admin',
                'status' => 'active', 'joined_at' => '2026-01-01 12:00:00+00:00',
            ])->save();
            $relationship = $label->artistRelationships()->firstOrNew(['artist_id' => $artist->id, 'ended_at' => null]);
            $relationship->forceFill([
                'public_id' => self::IDS['relationship'], 'relationship_type' => 'managing_label',
                'created_by_user_id' => $superadmin->id, 'started_at' => '2026-01-01 12:00:00+00:00',
            ])->save();

            $release = Release::withTrashed()->firstOrNew(['public_id' => self::IDS['release']]);
            $release->forceFill([
                'organization_id' => $label->id, 'artist_id' => null, 'type' => 'single',
                'status' => 'published', 'title' => 'Northern Lights',
                'description' => 'A deterministic published demo release.', 'release_date' => '2026-01-16',
                'created_by_user_id' => $labelUser->id, 'updated_by_user_id' => $labelUser->id,
                'published_at' => '2026-01-16 09:00:00+00:00', 'published_by_user_id' => $superadmin->id,
                'publication_version' => 1, 'deleted_at' => null,
            ])->save();
            DB::table('release_artists')->updateOrInsert(
                ['release_id' => $release->id, 'artist_id' => $artist->id],
                ['is_primary' => true, 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
            $track = Track::withTrashed()->firstOrNew(['public_id' => self::IDS['track']]);
            $track->forceFill([
                'release_id' => $release->id, 'position' => 1, 'title' => 'Northern Lights',
                'duration_ms' => 212000, 'isrc' => 'NODMO2600001', 'is_explicit' => false,
                'created_by_user_id' => $artistUser->id, 'updated_by_user_id' => $artistUser->id, 'deleted_at' => null,
            ])->save();

            $snapshot = $this->snapshot($label, $artist, $release, $track);
            $publication = ReleasePublication::query()->firstOrNew(['public_id' => self::IDS['publication']]);
            $publication->forceFill([
                'release_id' => $release->id, 'version' => 1, 'content_fingerprint' => hash('sha256', json_encode($snapshot)),
                'title' => $release->title, 'subtitle' => null, 'primary_artist_name' => $artist->profile->name,
                'release_type' => 'single', 'release_date' => '2026-01-16', 'cover_url' => null,
                'search_text' => 'Northern Lights Aurora Lines', 'snapshot' => $snapshot,
                'published_by_user_id' => $superadmin->id, 'published_at' => '2026-01-16 09:00:00+00:00', 'withdrawn_at' => null,
            ])->save();
            $publication->tracks()->updateOrCreate(['track_public_id' => $track->public_id], [
                'position' => 1, 'title' => $track->title, 'duration_ms' => $track->duration_ms,
                'snapshot' => $snapshot['tracks'][0],
            ]);
        });
    }

    private function user(string $id, string $email, string $displayName, string $password, bool $superadmin = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'public_id' => self::IDS[$id], 'password' => $password, 'email_verified_at' => '2026-01-01 12:00:00+00:00',
            'is_superadmin' => $superadmin,
        ])->save();
        $user->profile()->updateOrCreate([], ['display_name' => $displayName]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function snapshot(Organization $label, Artist $artist, Release $release, Track $track): array
    {
        return [
            'id' => $release->public_id,
            'owner' => ['type' => 'organization', 'id' => $label->public_id, 'name' => $label->profile->name],
            'type' => 'single', 'status' => 'published', 'title' => $release->title,
            'subtitle' => null, 'description' => $release->description, 'release_date' => '2026-01-16',
            'upc' => null, 'cover_media_id' => null,
            'artists' => [['id' => $artist->public_id, 'name' => $artist->profile->name, 'is_primary' => true, 'position' => 1]],
            'tracks' => [[
                'id' => $track->public_id, 'position' => 1, 'title' => $track->title,
                'duration_ms' => 212000, 'isrc' => $track->isrc, 'is_explicit' => false,
                'pages' => [], 'streaming_links' => [], 'credits' => [],
            ]],
            'pages' => [], 'streaming_links' => [], 'credits' => [], 'media' => [],
            'lifecycle' => ['published_at' => '2026-01-16T09:00:00.000Z', 'publication_version' => 1],
        ];
    }
}
