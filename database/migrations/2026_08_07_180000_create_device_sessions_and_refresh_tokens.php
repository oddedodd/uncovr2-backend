<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('client_type', ['portal', 'mobile']);
            $table->string('device_name', 100);
            $table->string('platform', 50)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->string('web_session_id')->nullable()->unique();
            $table->string('last_ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('last_used_at');
            $table->timestampTz('idle_expires_at');
            $table->timestampTz('absolute_expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 50)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['absolute_expires_at', 'revoked_at']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_session_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('generation');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('replaced_by_id')
                ->nullable()
                ->index()
                ->constrained('refresh_tokens')
                ->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['device_session_id', 'generation']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('device_session_id')
                ->nullable()
                ->unique()
                ->after('tokenable_id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_session_id');
        });

        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('device_sessions');
    }
};
