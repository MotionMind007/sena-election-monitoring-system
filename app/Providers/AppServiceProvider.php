<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ProductionConfiguration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        if ($this->app->isProduction()) {
            $command = $_SERVER['argv'][1] ?? null;
            if (! $this->app->runningInConsole() || $command !== 'ops:production-check') {
                $this->app->make(ProductionConfiguration::class)->assertSafe();
            }
        }

        $key = fn (Request $request): string => (string) ($request->user()?->id ?? $request->ip());
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('report-write', fn (Request $request) => Limit::perMinute(30)->by($key($request)));
        RateLimiter::for('ocr', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('decision', fn (Request $request) => Limit::perMinute(60)->by($key($request)));
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(120)->by($key($request)));
        RateLimiter::for('export', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($key($request)));

        Gate::define('manage-system', fn (User $user): bool => $user->hasRole(UserRole::SuperAdmin));
        Gate::define('coordinate-results', fn (User $user): bool => $user->hasAnyRole(
            UserRole::SuperAdmin,
            UserRole::Coordinator,
        ));

    }
}
