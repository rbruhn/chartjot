<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Keyed by IP, not journal: this runs before journal.token resolves a
        // journal, so excess traffic is rejected before the bcrypt token loop.
        // The AddOn posts one request per closed trade (plus the odd note), so
        // 60/min leaves ample headroom for a legitimate burst.
        RateLimiter::for('journal-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
