<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_uploads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->string('bucket', 100);
            $table->text('object_key');
            $table->string('expected_mime_type', 100);
            $table->unsignedBigInteger('maximum_byte_size');
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('actual_byte_size')->nullable();
            $table->string('actual_mime_type', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();

            $table->unique(['media_id', 'generation']);
            $table->unique(['bucket', 'object_key']);
            $table->index(['status', 'expires_at']);
            $table->index('requested_by_user_id');
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('active_upload_id')->nullable()->after('storage_key')->constrained('media_uploads')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable()->after('active_upload_id');
            $table->index('active_upload_id');
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('approved_fingerprint', 64)->nullable();
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('unpublished_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('publication_version')->default(0);
            $table->index(['status', 'scheduled_for']);
            $table->index('approved_by_user_id');
            $table->index('published_by_user_id');
        });

        Schema::create('release_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->char('content_fingerprint', 64);
            $table->text('request_note')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['release_id', 'created_at']);
            $table->index('requested_by_user_id');
            $table->index('decided_by_user_id');
        });

        Schema::create('release_publications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->char('content_fingerprint', 64);
            $table->jsonb('snapshot');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();

            $table->unique(['release_id', 'version']);
            $table->index(['release_id', 'withdrawn_at']);
            $table->index('published_by_user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE releases DROP CONSTRAINT IF EXISTS releases_status_check');
            DB::statement("ALTER TABLE releases ADD CONSTRAINT releases_status_check CHECK (status IN ('draft', 'review', 'scheduled', 'published', 'unpublished', 'archived'))");
            DB::statement("ALTER TABLE media_uploads ADD CONSTRAINT media_uploads_status_check CHECK (status IN ('requested', 'verified', 'active', 'superseded', 'failed', 'expired'))");
            DB::statement("ALTER TABLE release_approval_requests ADD CONSTRAINT release_approval_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
            DB::statement("CREATE UNIQUE INDEX release_approval_pending_unique ON release_approval_requests (release_id) WHERE status = 'pending'");
            DB::statement('CREATE UNIQUE INDEX release_publications_active_unique ON release_publications (release_id) WHERE withdrawn_at IS NULL');

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_release_audit_mutation()
                RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
                BEGIN
                    RAISE EXCEPTION 'Publication audit records are immutable';
                END;
                $$;
                REVOKE ALL ON FUNCTION prevent_release_audit_mutation() FROM PUBLIC;
                CREATE TRIGGER release_activity_events_immutable
                    BEFORE UPDATE OR DELETE ON release_activity_events
                    FOR EACH ROW EXECUTE FUNCTION prevent_release_audit_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS release_activity_events_immutable ON release_activity_events');
            DB::statement('DROP FUNCTION IF EXISTS prevent_release_audit_mutation()');
            DB::statement('ALTER TABLE releases DROP CONSTRAINT IF EXISTS releases_status_check');
            DB::statement("ALTER TABLE releases ADD CONSTRAINT releases_status_check CHECK (status IN ('draft'))");
        }
        Schema::dropIfExists('release_publications');
        Schema::dropIfExists('release_approval_requests');
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('published_by_user_id');
            $table->dropColumn(['submitted_at', 'approved_at', 'approved_fingerprint', 'scheduled_for', 'published_at', 'unpublished_at', 'archived_at', 'publication_version']);
        });
        Schema::table('media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_upload_id');
            $table->dropColumn('verified_at');
        });
        Schema::dropIfExists('media_uploads');
    }
};
