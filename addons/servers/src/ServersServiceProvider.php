<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

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

        // Per token, not per claimed server id, so wrong tokens cannot use up a real agent's budget; per IP bounds guessing.
        RateLimiter::for('server-reports', static function (Request $request): array {
            $token = $request->bearerToken();

            return [Limit::perMinute(300)->by('servers:ip:'.($request->ip() ?? 'unknown')),
                ...($token === null ? [] : [Limit::perSecond(1, 20)->by('servers:token:'.hash('sha256', $token))])];
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/servers.php');
        // Switched off, a guest gets the same 404 as an Owner: the switch runs before authentication's login redirect.
        $this->app->make(HttpKernel::class)->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, EnsureServersEnabled::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->call(new PruneServerSamples)->name('servers:prune-samples')->hourly()->withoutOverlapping(120)
                ->when(static fn (): bool => app(ServersAccess::class)->enabled());
        });
    }
}
