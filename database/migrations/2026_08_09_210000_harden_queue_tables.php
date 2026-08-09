<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX jobs_pending_queue_available_id_idx ON jobs (queue, available_at, id) WHERE reserved_at IS NULL');
            DB::statement('CREATE INDEX jobs_reserved_queue_reserved_id_idx ON jobs (queue, reserved_at, id) WHERE reserved_at IS NOT NULL');
            DB::statement('ALTER TABLE jobs SET (autovacuum_vacuum_scale_factor = 0.02, autovacuum_analyze_scale_factor = 0.01)');
        } else {
            Schema::table('jobs', function (Blueprint $table): void {
                $table->index(['queue', 'available_at', 'id'], 'jobs_pending_queue_available_id_idx');
                $table->index(['queue', 'reserved_at', 'id'], 'jobs_reserved_queue_reserved_id_idx');
            });
        }

        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->index('failed_at', 'failed_jobs_failed_at_idx');
        });

        Schema::table('job_batches', function (Blueprint $table): void {
            $table->index('finished_at', 'job_batches_finished_at_idx');
            $table->index('cancelled_at', 'job_batches_cancelled_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('job_batches', function (Blueprint $table): void {
            $table->dropIndex('job_batches_cancelled_at_idx');
            $table->dropIndex('job_batches_finished_at_idx');
        });

        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->dropIndex('failed_jobs_failed_at_idx');
        });

        Schema::table('jobs', function (Blueprint $table): void {
            $table->dropIndex('jobs_reserved_queue_reserved_id_idx');
            $table->dropIndex('jobs_pending_queue_available_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE jobs RESET (autovacuum_vacuum_scale_factor, autovacuum_analyze_scale_factor)');
        }
    }
};
