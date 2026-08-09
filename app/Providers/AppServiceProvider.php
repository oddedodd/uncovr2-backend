<?php

namespace App\Providers;

use App\Contracts\MediaStorage;
use App\Mail\Transport\ResendTransport;
use App\Models\Artist;
use App\Models\ArtistMembership;
use App\Models\Contributor;
use App\Models\Media;
use App\Models\Organization;
use App\Models\OrganizationArtistRelationship;
use App\Models\OrganizationMembership;
use App\Models\Release;
use App\Policies\ArtistMembershipPolicy;
use App\Policies\ArtistPolicy;
use App\Policies\ContributorPolicy;
use App\Policies\MediaPolicy;
use App\Policies\OrganizationArtistRelationshipPolicy;
use App\Policies\OrganizationMembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ReleasePolicy;
use App\Services\Media\SupabaseMediaStorage;
use App\Support\RequestPerformanceMetrics;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(RequestPerformanceMetrics::class);
        $this->app->bind(MediaStorage::class, SupabaseMediaStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationMembership::class, OrganizationMembershipPolicy::class);
        Gate::policy(OrganizationArtistRelationship::class, OrganizationArtistRelationshipPolicy::class);
        Gate::policy(Artist::class, ArtistPolicy::class);
        Gate::policy(ArtistMembership::class, ArtistMembershipPolicy::class);
        Gate::policy(Release::class, ReleasePolicy::class);
        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Contributor::class, ContributorPolicy::class);
        Gate::before(fn ($user): ?bool => $user->is_superadmin ? true : null);
        $this->configureResendTransport();
        $this->configureRateLimiting();
        $this->configurePerformanceMetrics();
        $this->validateEmailConfiguration();
        $this->validateQueueConfiguration();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    private function configurePerformanceMetrics(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (! $this->app->bound(RequestPerformanceMetrics::class)) {
                return;
            }

            $this->app->make(RequestPerformanceMetrics::class)
                ->recordQuery($query->sql, $query->time);
        });
    }

    private function configureResendTransport(): void
    {
        Mail::extend('resend', function (array $config): ResendTransport {
            $key = $config['key'] ?? config('services.resend.key');

            return new ResendTransport(\Resend::client($key));
        });
    }

    private function validateQueueConfiguration(): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }

        $retryAfter = (int) config('queue.connections.database.retry_after');
        $timeout = (int) config('queue.worker.timeout');

        if ($retryAfter <= $timeout) {
            throw new RuntimeException('DB_QUEUE_RETRY_AFTER must be greater than QUEUE_WORKER_TIMEOUT.');
        }

        if ($this->app->isProduction() && config('queue.failed.driver') !== 'database-uuids') {
            throw new RuntimeException('Production queue failures must use the database-uuids driver.');
        }
    }

    private function validateEmailConfiguration(): void
    {
        if (config('mail.default') !== 'resend') {
            return;
        }

        $required = [
            'RESEND_API_KEY' => config('services.resend.key'),
            'MAIL_FROM_ADDRESS' => config('mail.from.address'),
            'MAIL_REPLY_TO_ADDRESS' => config('mail.reply_to.address'),
        ];

        foreach ($required as $name => $value) {
            if (! is_string($value) || trim($value) === '' || str_contains($value, 'example.com')) {
                throw new RuntimeException("{$name} must be configured when the Resend mailer is enabled.");
            }
        }

        if ($this->app->isProduction()) {
            $secret = config('email.webhook.secret');
            $url = config('email.webhook.url');

            if (! is_string($secret) || ! str_starts_with($secret, 'whsec_')) {
                throw new RuntimeException('RESEND_WEBHOOK_SECRET must be configured in production.');
            }

            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                throw new RuntimeException('RESEND_WEBHOOK_URL must use HTTPS in production.');
            }
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(
            config('rate_limiting.public_per_minute'),
        )->by('public:'.($request->ip() ?? 'unknown')));

        RateLimiter::for('authenticated', function (Request $request) {
            $identifier = $request->user()?->getAuthIdentifier();
            $key = $identifier === null
                ? 'ip:'.($request->ip() ?? 'unknown')
                : 'user:'.$identifier;

            return Limit::perMinute(
                config('rate_limiting.authenticated_per_minute'),
            )->by('authenticated:'.$key);
        });

        RateLimiter::for('authentication', function (Request $request) {
            $ip = $request->ip() ?? 'unknown';
            $identity = $request->input('email');
            $normalizedIdentity = is_string($identity)
                ? strtolower(trim($identity))
                : '';
            $identityHash = hash('sha256', $normalizedIdentity ?: 'missing');

            return [
                Limit::perMinute(
                    config('rate_limiting.authentication_per_ip_per_minute'),
                )->by('authentication:ip:'.$ip),
                Limit::perMinute(
                    config('rate_limiting.authentication_per_identity_per_minute'),
                )->by('authentication:identity:'.$identityHash),
            ];
        });

        RateLimiter::for('refresh', function (Request $request) {
            $ip = $request->ip() ?? 'unknown';
            $token = $request->input('refresh_token');
            $tokenHash = hash('sha256', is_string($token) ? $token : 'missing');

            return [
                Limit::perMinute(config('rate_limiting.refresh_per_ip_per_minute'))
                    ->by('refresh:ip:'.$ip),
                Limit::perMinute(config('rate_limiting.refresh_per_token_per_minute'))
                    ->by('refresh:token:'.$tokenHash),
            ];
        });

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(
            config('rate_limiting.webhooks_per_minute'),
        )->by('webhooks:'.($request->ip() ?? 'unknown')));
    }
}
