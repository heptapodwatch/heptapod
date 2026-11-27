<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Heptapod\Addons\Servers\Http\AgentResponses;
use Heptapod\Addons\Servers\Http\EnsureServersEnabled;
use Heptapod\Contracts\ServersAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ServersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/servers.php', 'servers');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        Gate::policy(Server::class, ServerPolicy::class);

        // Per IP, before any token check, to bound guessing; the per-server limit follows authentication (ServerReportController).
        RateLimiter::for('server-reports', static fn (Request $request): Limit => Limit::perMinute(300)->by('servers:ip:'.($request->ip() ?? 'unknown')));
        $this->loadRoutesFrom(__DIR__.'/../routes/servers.php');
        $kernel = $this->app->make(HttpKernel::class);
        // Switched off, a guest gets the same 404 as an Owner: the switch runs before authentication's login redirect.
        $kernel->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, EnsureServersEnabled::class);
        $kernel->prependMiddleware(AgentResponses::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->call(new PruneServerSamples)->name('servers:prune-samples')->hourly()->withoutOverlapping(120)
                ->when(static fn (): bool => app(ServersAccess::class)->enabled());
        });
    }
}
