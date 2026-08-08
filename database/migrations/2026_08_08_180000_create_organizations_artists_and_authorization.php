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
            $table->boolean('is_superadmin')->default(false)->after('email_verification_version');
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();

            $table->index('created_by_user_id');
            $table->index('status');
        });

        Schema::create('organization_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->timestampsTz();
        });

        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at');
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'role', 'status']);
            $table->index('invited_by_user_id');
        });

        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('role', 30);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_sent_at');
            $table->unsignedInteger('send_count')->default(1);
            $table->timestampsTz();

            $table->index(['organization_id', 'email']);
            $table->index(['email', 'expires_at']);
            $table->index('invited_by_user_id');
            $table->index('accepted_by_user_id');
        });

        Schema::create('artists', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();

            $table->index('created_by_user_id');
            $table->index('status');
        });

        Schema::create('artist_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('biography')->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->timestampsTz();
        });

        Schema::create('artist_memberships', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at');
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();

            $table->unique(['artist_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['artist_id', 'role', 'status']);
            $table->index('invited_by_user_id');
        });

        Schema::create('organization_artist_relationships', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_type', 30)->default('managing_label');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'ended_at']);
            $table->index(['artist_id', 'ended_at']);
            $table->index('created_by_user_id');
        });

        $this->createActiveRelationshipIndex();
        $this->createPendingInvitationIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_artist_relationships');
        Schema::dropIfExists('artist_memberships');
        Schema::dropIfExists('artist_profiles');
        Schema::dropIfExists('artists');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organization_profiles');
        Schema::dropIfExists('organizations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_superadmin');
        });
    }

    private function createActiveRelationshipIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organization_artist_relationships_active_unique
            ON organization_artist_relationships (organization_id, artist_id)
            WHERE ended_at IS NULL
        SQL);
    }

    private function createPendingInvitationIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organization_invitations_pending_unique
            ON organization_invitations (organization_id, email)
            WHERE accepted_at IS NULL AND revoked_at IS NULL
        SQL);
    }
};
