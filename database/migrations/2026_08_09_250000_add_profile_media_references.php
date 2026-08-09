<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table): void {
            $table->foreignId('logo_media_id')
                ->nullable()
                ->index()
                ->constrained('media')
                ->nullOnDelete();
        });

        Schema::table('artist_profiles', function (Blueprint $table): void {
            $table->foreignId('logo_media_id')
                ->nullable()
                ->index()
                ->constrained('media')
                ->nullOnDelete();
            $table->foreignId('image_media_id')
                ->nullable()
                ->index()
                ->constrained('media')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('artist_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('image_media_id');
            $table->dropConstrainedForeignId('logo_media_id');
        });

        Schema::table('organization_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_media_id');
        });
    }
};
