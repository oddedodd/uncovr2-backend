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
            $table->string('status', 20)->default('active')->after('is_superadmin');
            $table->timestampTz('suspended_at')->nullable()->after('status');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
            $table->index(['status', 'public_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_suspension_state_check CHECK ((status = 'active' AND suspended_at IS NULL AND suspension_reason IS NULL) OR (status = 'suspended' AND suspended_at IS NOT NULL AND suspension_reason IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_suspension_state_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status', 'public_id']);
            $table->dropColumn(['status', 'suspended_at', 'suspension_reason']);
        });
    }
};
