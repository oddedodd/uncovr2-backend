<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_sessions', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'revoked_at', 'absolute_expires_at', 'last_used_at'],
                'device_sessions_user_active_listing_idx',
            );
        });

        Schema::table('organization_profiles', function (Blueprint $table): void {
            $table->index('logo_media_id', 'organization_profiles_logo_media_id_idx');
        });

        Schema::table('artist_profiles', function (Blueprint $table): void {
            $table->index('logo_media_id', 'artist_profiles_logo_media_id_idx');
            $table->index('image_media_id', 'artist_profiles_image_media_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('artist_profiles', function (Blueprint $table): void {
            $table->dropIndex('artist_profiles_image_media_id_idx');
            $table->dropIndex('artist_profiles_logo_media_id_idx');
        });

        Schema::table('organization_profiles', function (Blueprint $table): void {
            $table->dropIndex('organization_profiles_logo_media_id_idx');
        });

        Schema::table('device_sessions', function (Blueprint $table): void {
            $table->dropIndex('device_sessions_user_active_listing_idx');
        });
    }
};
