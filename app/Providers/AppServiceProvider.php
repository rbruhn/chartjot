<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // Volt pages submit via POST /livewire/update, not their own route, so
        // a route-level throttle (e.g. on register / forgot-password) would
        // only limit page loads. Persisting it makes Livewire re-apply the
        // page route's throttle to that component's update requests too.
        Livewire::addPersistentMiddleware([ThrottleRequests::class]);
    }
}
