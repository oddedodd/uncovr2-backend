<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes indexes that are exact duplicates or strict prefixes of a wider index.
 *
 * Each one is dead weight on every insert and update of its table without ever
 * being the best plan for a read. The three profile indexes were added a second
 * time by the hot path migration; the other two are prefixes of composites added
 * later.
 */
return new class extends Migration
{
    /**
     * CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction, and Laravel
     * wraps Postgres migrations in one by default.
     */
    public $withinTransaction = false;

    /** @var array<string, string> index name => the index that already covers it */
    private array $redundant = [
        'organization_profiles_logo_media_id_idx' => 'organization_profiles_logo_media_id_index',
        'artist_profiles_logo_media_id_idx' => 'artist_profiles_logo_media_id_index',
        'artist_profiles_image_media_id_idx' => 'artist_profiles_image_media_id_index',
        'release_artists_artist_id_index' => 'release_artists_artist_release_idx',
        'device_sessions_user_id_revoked_at_index' => 'device_sessions_user_active_listing_idx',
    ];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach (array_keys($this->redundant) as $index) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$this->qualified($index));
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $definitions = [
            'organization_profiles_logo_media_id_idx' => 'organization_profiles (logo_media_id)',
            'artist_profiles_logo_media_id_idx' => 'artist_profiles (logo_media_id)',
            'artist_profiles_image_media_id_idx' => 'artist_profiles (image_media_id)',
            'release_artists_artist_id_index' => 'release_artists (artist_id)',
            'device_sessions_user_id_revoked_at_index' => 'device_sessions (user_id, revoked_at)',
        ];

        foreach ($definitions as $index => $target) {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$this->quote($index)
                .' ON '.$this->schema().'.'.$target
            );
        }
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    private function schema(): string
    {
        $searchPath = Schema::getConnection()->getConfig('search_path') ?? 'public';

        return $this->quote(explode(',', (string) $searchPath)[0]);
    }

    private function qualified(string $index): string
    {
        return $this->schema().'.'.$this->quote($index);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', trim($identifier, " \t\n\r\0\x0B\"")).'"';
    }
};
