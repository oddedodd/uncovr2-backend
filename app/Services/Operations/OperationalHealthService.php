<?php

namespace App\Services\Operations;

use App\Models\EmailWebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OperationalHealthService
{
    /** @return array{status: string, window_minutes: int, metrics: array<string, int|float>, alerts: list<array{code: string, severity: string}>} */
    public function check(bool $emitAlerts = true): array
    {
        $windowMinutes = max(1, (int) config('operations.monitor_window_minutes'));
        $since = now()->subMinutes($windowMinutes);
        $eventQuery = EmailWebhookEvent::query()->where('event_occurred_at', '>=', $since);

        $emailVolume = (clone $eventQuery)->distinct()->count('email_delivery_id');
        $providerFailures = (clone $eventQuery)->where('event_type', 'email.failed')->count();
        $bounces = (clone $eventQuery)->where('event_type', 'email.bounced')->count();
        $complaints = (clone $eventQuery)->where('event_type', 'email.complained')->count();
        $queueFailures = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();
        $bounceRate = $emailVolume === 0 ? 0.0 : round($bounces / $emailVolume, 6);
        $complaintRate = $emailVolume === 0 ? 0.0 : round($complaints / $emailVolume, 6);
        $metrics = [
            'email_volume' => $emailVolume,
            'provider_failures' => $providerFailures,
            'queue_failures' => $queueFailures,
            'bounces' => $bounces,
            'complaints' => $complaints,
            'bounce_rate' => $bounceRate,
            'complaint_rate' => $complaintRate,
        ];
        $alerts = $this->alerts($metrics);

        if ($emitAlerts) {
            foreach ($alerts as $alert) {
                $this->emit($alert, $metrics, $windowMinutes);
            }
        }

        return [
            'status' => $alerts === [] ? 'ok' : 'alert',
            'window_minutes' => $windowMinutes,
            'metrics' => $metrics,
            'alerts' => $alerts,
        ];
    }

    /** @param array<string, int|float> $metrics */
    private function alerts(array $metrics): array
    {
        $alerts = [];

        if ($metrics['queue_failures'] > config('operations.max_queue_failures')) {
            $alerts[] = ['code' => 'queue_failures', 'severity' => 'critical'];
        }

        if ($metrics['provider_failures'] > config('operations.max_provider_failures')) {
            $alerts[] = ['code' => 'email_provider_failures', 'severity' => 'critical'];
        }

        if ($metrics['email_volume'] >= config('operations.minimum_email_volume')) {
            if ($metrics['bounce_rate'] > config('operations.max_bounce_rate')) {
                $alerts[] = ['code' => 'email_bounce_rate', 'severity' => 'warning'];
            }

            if ($metrics['complaint_rate'] > config('operations.max_complaint_rate')) {
                $alerts[] = ['code' => 'email_complaint_rate', 'severity' => 'critical'];
            }
        }

        return $alerts;
    }

    /** @param array{code: string, severity: string} $alert @param array<string, int|float> $metrics */
    private function emit(array $alert, array $metrics, int $windowMinutes): void
    {
        $cacheKey = 'operations:alert:'.$alert['code'];
        $cooldown = now()->addMinutes(max(1, (int) config('operations.alert_cooldown_minutes')));

        if (! Cache::add($cacheKey, true, $cooldown)) {
            return;
        }

        Log::channel(config('operations.alert_channel'))->log(
            $alert['severity'],
            'Operational threshold exceeded.',
            [
                'event' => 'operations.threshold_exceeded',
                'alert_code' => $alert['code'],
                'window_minutes' => $windowMinutes,
                'metrics' => $metrics,
            ],
        );
    }
}
