<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
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
    }
}
