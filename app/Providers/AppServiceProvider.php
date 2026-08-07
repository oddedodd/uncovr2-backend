<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
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
                )->by('authentication:identity:'.$ip.':'.$identityHash),
            ];
        });
    }
}
