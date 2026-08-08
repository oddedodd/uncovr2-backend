<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestampTz('featured_at')->nullable()->after('publication_version');
            $table->index(['status', 'featured_at']);
        });

        Schema::table('release_publications', function (Blueprint $table): void {
            $table->string('title', 200)->nullable()->after('content_fingerprint');
            $table->string('subtitle', 200)->nullable()->after('title');
            $table->string('primary_artist_name', 150)->nullable()->after('subtitle');
            $table->string('release_type', 20)->nullable()->after('primary_artist_name');
            $table->date('release_date')->nullable()->after('release_type');
            $table->text('cover_url')->nullable()->after('release_date');
            $table->text('search_text')->nullable()->after('cover_url');
            $table->index(['withdrawn_at', 'published_at']);
            $table->index(['withdrawn_at', 'release_date']);
        });

        Schema::create('release_publication_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_publication_id')->constrained()->cascadeOnDelete();
            $table->ulid('track_public_id');
            $table->unsignedInteger('position');
            $table->string('title', 200);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('snapshot');
            $table->timestampsTz();

            $table->unique(['release_publication_id', 'track_public_id']);
            $table->index(['track_public_id', 'release_publication_id']);
        });

        DB::table('release_publications')->orderBy('id')->each(function (object $publication): void {
            $snapshot = is_string($publication->snapshot) ? json_decode($publication->snapshot, true) : (array) $publication->snapshot;
            $primaryArtist = collect($snapshot['artists'] ?? [])->firstWhere('is_primary', true);
            $coverId = $snapshot['cover_media_id'] ?? null;
            DB::table('release_publications')->where('id', $publication->id)->update([
                'title' => $snapshot['title'] ?? null,
                'subtitle' => $snapshot['subtitle'] ?? null,
                'primary_artist_name' => $primaryArtist['name'] ?? null,
                'release_type' => $snapshot['type'] ?? null,
                'release_date' => $snapshot['release_date'] ?? null,
                'cover_url' => $coverId ? ($snapshot['media'][$coverId] ?? null) : null,
                'search_text' => trim(implode(' ', array_filter([$snapshot['title'] ?? null, $snapshot['subtitle'] ?? null, $primaryArtist['name'] ?? null]))),
            ]);
            foreach ($snapshot['tracks'] ?? [] as $track) {
                DB::table('release_publication_tracks')->insert([
                    'release_publication_id' => $publication->id,
                    'track_public_id' => $track['id'],
                    'position' => $track['position'],
                    'title' => $track['title'],
                    'duration_ms' => $track['duration_ms'] ?? null,
                    'snapshot' => json_encode($track, JSON_THROW_ON_ERROR),
                    'created_at' => $publication->published_at,
                    'updated_at' => $publication->published_at,
                ]);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX release_publications_search_idx ON release_publications USING gin (to_tsvector('simple', coalesce(search_text, ''))) WHERE withdrawn_at IS NULL");
            DB::statement("CREATE INDEX organization_profiles_public_search_idx ON organization_profiles USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(description, '')))");
            DB::statement("CREATE INDEX artist_profiles_public_search_idx ON artist_profiles USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(biography, '')))");
            DB::statement("ALTER TABLE release_publications ADD CONSTRAINT release_publications_type_check CHECK (release_type IS NULL OR release_type IN ('album', 'ep', 'single'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS release_publications_search_idx');
            DB::statement('DROP INDEX IF EXISTS organization_profiles_public_search_idx');
            DB::statement('DROP INDEX IF EXISTS artist_profiles_public_search_idx');
        }
        Schema::dropIfExists('release_publication_tracks');
        Schema::table('release_publications', function (Blueprint $table): void {
            $table->dropIndex(['withdrawn_at', 'published_at']);
            $table->dropIndex(['withdrawn_at', 'release_date']);
            $table->dropColumn(['title', 'subtitle', 'primary_artist_name', 'release_type', 'release_date', 'cover_url', 'search_text']);
        });
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex(['status', 'featured_at']);
            $table->dropColumn('featured_at');
        });
    }
};
