<?php

namespace Tests\Feature\Operations;

use App\Jobs\PublishScheduledRelease;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Organizations\OrganizationInvitationNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QueueOperationsTest extends TestCase
{
    public function test_database_queue_defaults_are_safe_for_production_workers(): void
    {
        $this->assertTrue(config('queue.connections.database.after_commit'));
        $this->assertSame('database-uuids', config('queue.failed.driver'));
        $this->assertGreaterThan(
            config('queue.worker.timeout'),
            config('queue.connections.database.retry_after'),
        );
        $this->assertSame('emails,publishing,default', config('queue.worker.queues'));
    }

    public function test_queued_work_has_bounded_retries_timeouts_and_explicit_queues(): void
    {
        $publishing = new PublishScheduledRelease(1, 2);

        $this->assertSame('publishing', $publishing->queue);
        $this->assertTrue($publishing->afterCommit);
        $this->assertSame(3, $publishing->tries);
        $this->assertSame(3, $publishing->maxExceptions);
        $this->assertSame(120, $publishing->timeout);
        $this->assertTrue($publishing->failOnTimeout);
        $this->assertSame([60, 300, 900], $publishing->backoff);

        $notifications = [
            new VerifyEmailNotification(1),
            new ResetPasswordNotification('secret-reset-token'),
            new OrganizationInvitationNotification('secret-invitation-token'),
        ];

        foreach ($notifications as $notification) {
            $this->assertSame('emails', $notification->queue);
            $this->assertTrue($notification->afterCommit);
            $this->assertSame(3, $notification->tries);
            $this->assertSame(3, $notification->maxExceptions);
            $this->assertSame(60, $notification->timeout);
            $this->assertTrue($notification->failOnTimeout);
            $this->assertSame([60, 300, 900], $notification->backoff());
        }
    }

    public function test_failed_jobs_are_logged_without_payloads_or_exception_messages(): void
    {
        Log::spy();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->once()->andReturn('emails');
        $job->shouldReceive('getJobId')->once()->andReturn('42');
        $job->shouldReceive('uuid')->once()->andReturn('job-uuid');
        $job->shouldReceive('resolveName')->once()->andReturn(VerifyEmailNotification::class);
        $job->shouldReceive('attempts')->once()->andReturn(3);

        Event::dispatch(new JobFailed(
            'database',
            $job,
            new RuntimeException('private-provider-response'),
        ));

        Log::shouldHaveReceived('error')->once()->with(
            'Queue job failed.',
            Mockery::on(function (array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $context['event'] === 'queue.job_failed'
                    && $context['queue'] === 'emails'
                    && $context['attempts'] === 3
                    && $context['exception_class'] === RuntimeException::class
                    && ! str_contains($encoded, 'private-provider-response')
                    && ! array_key_exists('payload', $context);
            }),
        );
    }

    public function test_busy_queues_emit_structured_warning_metadata(): void
    {
        Log::spy();

        Event::dispatch(new QueueBusy('database', 'publishing', 101));

        Log::shouldHaveReceived('warning')->once()->with(
            'Queue backlog threshold exceeded.',
            Mockery::on(fn (array $context): bool => $context === [
                'event' => 'queue.busy',
                'connection' => 'database',
                'queue' => 'publishing',
                'size' => 101,
                'threshold' => 100,
            ]),
        );
    }

    public function test_queue_monitoring_and_retention_are_scheduled(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())
            ->pluck('command')
            ->filter()
            ->values();

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'queue:monitor')));
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'queue:prune-failed')));
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'queue:prune-batches')));
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'operations:check')));
    }

    public function test_failed_jobs_are_durable_and_pruned_after_the_retention_window(): void
    {
        $uuid = (string) Str::uuid();
        $payload = json_encode(['uuid' => $uuid], JSON_THROW_ON_ERROR);

        $this->app->make('queue.failer')->log(
            'database',
            'emails',
            $payload,
            new RuntimeException('provider failed'),
        );

        $this->assertDatabaseHas('failed_jobs', [
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'emails',
        ]);

        $this->getConnection()->table('failed_jobs')
            ->where('uuid', $uuid)
            ->update(['failed_at' => now()->subHours(721)]);

        $this->artisan('queue:prune-failed', ['--hours' => 720])
            ->assertSuccessful();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_queue_tables_have_worker_and_retention_indexes(): void
    {
        $jobIndexes = collect(Schema::getIndexes('jobs'))->pluck('name');
        $failedIndexes = collect(Schema::getIndexes('failed_jobs'))->pluck('name');
        $batchIndexes = collect(Schema::getIndexes('job_batches'))->pluck('name');

        $this->assertContains('jobs_pending_queue_available_id_idx', $jobIndexes);
        $this->assertContains('jobs_reserved_queue_reserved_id_idx', $jobIndexes);
        $this->assertContains('failed_jobs_failed_at_idx', $failedIndexes);
        $this->assertContains('job_batches_finished_at_idx', $batchIndexes);
        $this->assertContains('job_batches_cancelled_at_idx', $batchIndexes);
    }
}
