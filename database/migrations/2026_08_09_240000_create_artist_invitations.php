<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
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

            $table->index(['artist_id', 'email']);
            $table->index(['email', 'expires_at']);
            $table->index('invited_by_user_id');
            $table->index('accepted_by_user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE artist_invitations ADD CONSTRAINT artist_invitations_role_check CHECK (role IN ('artist_admin', 'artist_user'))");
            DB::statement('ALTER TABLE artist_invitations ADD CONSTRAINT artist_invitations_send_count_check CHECK (send_count >= 1)');
            DB::statement('CREATE UNIQUE INDEX artist_invitations_pending_unique ON artist_invitations (artist_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_invitations');
    }
};
