<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $constraints = [
        'media_owner_check' => 'media CHECK ((organization_id IS NOT NULL)::int + (artist_id IS NOT NULL)::int = 1)',
        'media_kind_check' => "media CHECK (kind IN ('image', 'audio', 'video', 'document'))",
        'media_status_check' => "media CHECK (status IN ('pending', 'ready', 'failed'))",
        'contributors_owner_check' => 'contributors CHECK ((organization_id IS NOT NULL)::int + (artist_id IS NOT NULL)::int = 1)',
        'releases_owner_check' => 'releases CHECK ((organization_id IS NOT NULL)::int + (artist_id IS NOT NULL)::int = 1)',
        'releases_type_check' => "releases CHECK (type IN ('album', 'ep', 'single'))",
        'releases_status_check' => "releases CHECK (status IN ('draft'))",
        'release_artists_position_check' => 'release_artists CHECK (position >= 1)',
        'tracks_position_check' => 'tracks CHECK (position >= 1)',
        'pages_owner_check' => 'pages CHECK ((release_id IS NOT NULL)::int + (track_id IS NOT NULL)::int = 1)',
        'pages_position_check' => 'pages CHECK (position >= 1)',
        'content_blocks_position_check' => 'content_blocks CHECK (position >= 1)',
        'content_blocks_version_check' => 'content_blocks CHECK (version >= 1)',
        'content_blocks_type_check' => "content_blocks CHECK (type IN ('heading', 'text', 'image', 'gallery', 'video', 'quote', 'lyrics'))",
        'content_block_versions_version_check' => 'content_block_versions CHECK (version >= 1)',
        'streaming_links_owner_check' => 'streaming_links CHECK ((release_id IS NOT NULL)::int + (track_id IS NOT NULL)::int = 1)',
        'streaming_links_position_check' => 'streaming_links CHECK (position >= 1)',
        'streaming_links_service_check' => "streaming_links CHECK (service IN ('spotify', 'apple_music', 'tidal', 'youtube_music', 'soundcloud', 'bandcamp', 'other'))",
        'credits_owner_check' => 'credits CHECK ((release_id IS NOT NULL)::int + (track_id IS NOT NULL)::int = 1)',
        'credits_position_check' => 'credits CHECK (position >= 1)',
        'credits_role_check' => "credits CHECK (role IN ('primary_artist', 'featured_artist', 'producer', 'songwriter', 'composer', 'lyricist', 'musician', 'engineer', 'photographer', 'designer', 'other'))",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->constraints as $name => $definition) {
            [$table, $check] = explode(' ', $definition, 2);
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$check}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->constraints as $name => $definition) {
            [$table] = explode(' ', $definition, 2);
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        }
    }
};
