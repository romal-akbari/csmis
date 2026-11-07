<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('system-reset-preview', fn ($request) => Limit::perMinute(10)->by('reset-preview:'.$request->user()->id));
        RateLimiter::for('system-reset', fn ($request) => Limit::perMinute(5)->by('reset-submit:'.$request->user()->id));
        RateLimiter::for('login', function ($request) {
            return Limit::perMinute(5)->by(
                mb_strtolower((string) $request->input('username')).'|'.$request->ip(),
            );
        });
    }
}
