<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->pinUrlGenerationToAppUrl();

        RateLimiter::for('domain-check', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(60)->by($request->user()->id)
                : Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('http3-check', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(30)->by($request->user()->id)
                : Limit::perHour(60)->by($request->ip());
        });

        RateLimiter::for('ip-lookup', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(45)->by($request->user()->id)
                : Limit::perHour(60)->by($request->ip());
        });

        RateLimiter::for('redirect-check', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(30)->by($request->user()->id)
                : Limit::perHour(60)->by($request->ip());
        });

        RateLimiter::for('dns-bulk', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(30)->by($request->user()->id)
                : Limit::perMinute(5)->by($request->ip());
        });
    }

    /**
     * Generate every URL from APP_URL instead of from the incoming request.
     *
     * Without this, a request carrying someone else's host produces links on
     * that host -- and a password-reset mail then delivers a valid token to a
     * server the attacker controls. TrustHosts covers this too, but Laravel
     * disables it in the local environment, and mail sent from a queue or a
     * console command has no request to take a host from at all.
     */
    private function pinUrlGenerationToAppUrl(): void
    {
        $appUrl = (string) config('app.url');

        if ($appUrl === '') {
            return;
        }

        URL::forceRootUrl($appUrl);

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
