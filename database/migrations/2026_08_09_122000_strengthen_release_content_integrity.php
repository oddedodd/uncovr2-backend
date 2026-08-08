<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $constraints = [
        'media_dimensions_check' => 'media CHECK ((byte_size IS NULL OR byte_size >= 0) AND (width IS NULL OR width >= 1) AND (height IS NULL OR height >= 1))',
        'releases_upc_check' => "releases CHECK (upc IS NULL OR upc ~ '^[0-9]{12,14}$')",
        'tracks_metadata_check' => "tracks CHECK ((duration_ms IS NULL OR duration_ms >= 0) AND (isrc IS NULL OR isrc ~ '^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$'))",
        'content_blocks_payload_check' => "content_blocks CHECK (jsonb_typeof(payload) = 'object')",
        'content_block_versions_type_check' => "content_block_versions CHECK (type IN ('heading', 'text', 'image', 'gallery', 'video', 'quote', 'lyrics'))",
        'content_block_versions_payload_check' => "content_block_versions CHECK (jsonb_typeof(payload) = 'object')",
        'streaming_links_url_check' => "streaming_links CHECK (url LIKE 'https://%')",
    ];

    private array $indexes = [
        'streaming_links_release_position_unique' => 'streaming_links (release_id, position) WHERE release_id IS NOT NULL AND deleted_at IS NULL',
        'streaming_links_track_position_unique' => 'streaming_links (track_id, position) WHERE track_id IS NOT NULL AND deleted_at IS NULL',
        'credits_release_position_unique' => 'credits (release_id, position) WHERE release_id IS NOT NULL AND deleted_at IS NULL',
        'credits_track_position_unique' => 'credits (track_id, position) WHERE track_id IS NOT NULL AND deleted_at IS NULL',
    ];

    public function up(): void
    {
        foreach ($this->indexes as $name => $definition) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON {$definition}");
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->constraints as $name => $definition) {
            [$table, $check] = explode(' ', $definition, 2);
            DB::statement(<<<SQL
                DO \$\$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint
                        WHERE conname = '{$name}'
                          AND conrelid = '{$table}'::regclass
                    ) THEN
                        ALTER TABLE {$table} ADD CONSTRAINT {$name} {$check};
                    END IF;
                END
                \$\$
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $name => $definition) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->constraints as $name => $definition) {
            [$table] = explode(' ', $definition, 2);
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
