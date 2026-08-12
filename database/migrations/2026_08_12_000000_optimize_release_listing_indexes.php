<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'releases_listing_created_id_idx');
            $table->index(['organization_id', 'created_at', 'id'], 'releases_organization_listing_idx');
            $table->index(['artist_id', 'created_at', 'id'], 'releases_artist_listing_idx');
            $table->index(['status', 'created_at', 'id'], 'releases_status_listing_idx');
            $table->index(['type', 'created_at', 'id'], 'releases_type_listing_idx');
        });

        Schema::table('release_artists', function (Blueprint $table): void {
            $table->index(['artist_id', 'release_id'], 'release_artists_artist_release_idx');
        });
    }

    public function down(): void
    {
        Schema::table('release_artists', function (Blueprint $table): void {
            $table->dropIndex('release_artists_artist_release_idx');
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('releases_type_listing_idx');
            $table->dropIndex('releases_status_listing_idx');
            $table->dropIndex('releases_artist_listing_idx');
            $table->dropIndex('releases_organization_listing_idx');
            $table->dropIndex('releases_listing_created_id_idx');
        });
    }
};
