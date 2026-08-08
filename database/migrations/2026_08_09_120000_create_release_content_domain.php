<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('storage_disk', 50)->nullable();
            $table->text('storage_key')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['organization_id', 'created_at']);
            $table->index(['artist_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('contributors', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('display_name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['organization_id', 'display_name']);
            $table->index(['artist_id', 'display_name']);
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            $table->string('title', 200);
            $table->string('subtitle', 200)->nullable();
            $table->text('description')->nullable();
            $table->date('release_date')->nullable();
            $table->string('upc', 20)->nullable();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['artist_id', 'status', 'created_at']);
            $table->index('cover_media_id');
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('release_artists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('position');
            $table->timestampsTz();

            $table->unique(['release_id', 'artist_id']);
            $table->unique(['release_id', 'position']);
            $table->index('artist_id');
        });

        Schema::create('release_editors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['release_id', 'user_id']);
            $table->index('user_id');
            $table->index('granted_by_user_id');
        });

        Schema::create('tracks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title', 200);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('isrc', 20)->nullable();
            $table->boolean('is_explicit')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['release_id', 'position']);
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title', 200)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['release_id', 'position']);
            $table->index(['track_id', 'position']);
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('content_blocks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type', 20);
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('payload');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['page_id', 'position']);
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('content_block_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_block_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('type', 20);
            $table->jsonb('payload');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['content_block_id', 'version']);
            $table->index('created_by_user_id');
        });

        Schema::create('streaming_links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('service', 30);
            $table->string('url', 2048);
            $table->unsignedInteger('position');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['release_id', 'position']);
            $table->index(['track_id', 'position']);
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('credits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contributor_id')->constrained()->restrictOnDelete();
            $table->string('role', 40);
            $table->string('detail', 200)->nullable();
            $table->unsignedInteger('position');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['release_id', 'position']);
            $table->index(['track_id', 'position']);
            $table->index('contributor_id');
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });

        Schema::create('release_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('subject_type', 50)->nullable();
            $table->ulid('subject_public_id')->nullable();
            $table->jsonb('changes')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['release_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_public_id']);
        });

        DB::statement('CREATE UNIQUE INDEX release_artists_primary_unique ON release_artists (release_id) WHERE is_primary = true');
        DB::statement('CREATE UNIQUE INDEX tracks_release_position_unique ON tracks (release_id, position) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX pages_release_position_unique ON pages (release_id, position) WHERE release_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX pages_track_position_unique ON pages (track_id, position) WHERE track_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX content_blocks_page_position_unique ON content_blocks (page_id, position) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX streaming_links_release_service_unique ON streaming_links (release_id, service) WHERE release_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX streaming_links_track_service_unique ON streaming_links (track_id, service) WHERE track_id IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('release_activity_events');
        Schema::dropIfExists('credits');
        Schema::dropIfExists('streaming_links');
        Schema::dropIfExists('content_block_versions');
        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('release_editors');
        Schema::dropIfExists('release_artists');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('contributors');
        Schema::dropIfExists('media');
    }
};
