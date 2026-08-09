<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_message_id', 100)->unique();
            $table->string('status', 32);
            $table->timestampTz('last_event_at');
            $table->timestampTz('terminal_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'last_event_at']);
        });

        Schema::create('email_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('svix_id', 150)->unique();
            $table->foreignId('email_delivery_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->timestampTz('event_occurred_at');
            $table->timestampTz('processed_at');
            $table->timestampsTz();

            $table->index(['event_type', 'event_occurred_at']);
            $table->index(['email_delivery_id', 'event_occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE email_deliveries
                ADD CONSTRAINT email_deliveries_status_check
                CHECK (status IN ('sent', 'delivery_delayed', 'delivered', 'bounced', 'complained', 'suppressed', 'failed'))
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE email_webhook_events
                ADD CONSTRAINT email_webhook_events_type_check
                CHECK (event_type IN ('email.sent', 'email.delivery_delayed', 'email.delivered', 'email.bounced', 'email.complained', 'email.suppressed', 'email.failed'))
            SQL);
            DB::statement('REVOKE ALL ON TABLE email_deliveries, email_webhook_events FROM anon, authenticated, service_role');
            DB::statement('REVOKE ALL ON SEQUENCE email_deliveries_id_seq, email_webhook_events_id_seq FROM anon, authenticated, service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_webhook_events');
        Schema::dropIfExists('email_deliveries');
    }
};
