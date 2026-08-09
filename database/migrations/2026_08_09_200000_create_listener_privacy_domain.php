<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('deletion_requested_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->index('deletion_requested_at');
        });

        Schema::create('artist_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'artist_id']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['artist_id', 'created_at']);
        });
        Schema::create('release_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'release_id']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['release_id', 'created_at']);
        });
        Schema::create('track_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'track_id']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['track_id', 'created_at']);
        });
        Schema::create('listener_collections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'updated_at', 'public_id']);
        });
        Schema::create('listener_collection_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('listener_collection_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('release_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestampsTz();
            $table->unique(['listener_collection_id', 'position']);
            $table->unique(['listener_collection_id', 'release_id']);
            $table->unique(['listener_collection_id', 'track_id']);
            $table->index('release_id');
            $table->index('track_id');
        });
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 50);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('push_enabled')->default(false);
            $table->boolean('in_app_enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['user_id', 'topic']);
        });
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->char('token_hash', 64)->unique();
            $table->text('push_token');
            $table->timestampTz('enabled_at');
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
            $table->index(['user_id', 'disabled_at']);
            $table->index(['device_session_id', 'disabled_at']);
        });
        Schema::create('listener_notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('title', 200);
            $table->text('body');
            $table->jsonb('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at', 'public_id']);
            $table->index(['user_id', 'read_at', 'created_at']);
        });
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 50);
            $table->boolean('granted');
            $table->string('policy_version', 50);
            $table->string('source', 30);
            $table->char('ip_address_hash', 64)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();
            $table->index(['user_id', 'purpose', 'recorded_at']);
        });
        Schema::create('account_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('scheduled');
            $table->timestampTz('requested_at');
            $table->timestampTz('scheduled_for');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'scheduled_for']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE listener_collection_items ADD CONSTRAINT listener_collection_items_target_check CHECK ((item_type = 'release' AND release_id IS NOT NULL AND track_id IS NULL) OR (item_type = 'track' AND track_id IS NOT NULL AND release_id IS NULL))");
            DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_topic_check CHECK (topic IN ('artist_updates', 'release_updates', 'product_updates', 'marketing'))");
            DB::statement("ALTER TABLE push_devices ADD CONSTRAINT push_devices_platform_check CHECK (platform IN ('ios', 'android'))");
            DB::statement("ALTER TABLE consent_records ADD CONSTRAINT consent_records_purpose_check CHECK (purpose IN ('terms', 'privacy', 'marketing_email', 'marketing_push', 'analytics'))");
            DB::statement("ALTER TABLE account_deletion_requests ADD CONSTRAINT account_deletion_requests_status_check CHECK (status IN ('scheduled', 'cancelled', 'completed'))");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_consent_record_mutation()
                RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
                BEGIN
                    RAISE EXCEPTION 'Consent records are immutable';
                END;
                $$;
                REVOKE ALL ON FUNCTION prevent_consent_record_mutation() FROM PUBLIC;
                CREATE TRIGGER consent_records_immutable
                    BEFORE UPDATE OR DELETE ON consent_records
                    FOR EACH ROW EXECUTE FUNCTION prevent_consent_record_mutation();
                SQL);
            DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA laravel FROM anon, authenticated, service_role');
            DB::statement('REVOKE ALL ON ALL SEQUENCES IN SCHEMA laravel FROM anon, authenticated, service_role');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS consent_records_immutable ON consent_records');
            DB::statement('DROP FUNCTION IF EXISTS prevent_consent_record_mutation()');
        }
        Schema::dropIfExists('account_deletion_requests');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('listener_notifications');
        Schema::dropIfExists('push_devices');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('listener_collection_items');
        Schema::dropIfExists('listener_collections');
        Schema::dropIfExists('track_favorites');
        Schema::dropIfExists('release_favorites');
        Schema::dropIfExists('artist_follows');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deletion_requested_at']);
            $table->dropColumn(['deletion_requested_at', 'anonymized_at']);
        });
    }
};
