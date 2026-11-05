<?php

declare(strict_types=1);

namespace Heptapod\Providers;

use Heptapod\Contracts\OhDear\OhDearClientFactory;
use Heptapod\Testing\RecordedOhDearClientFactory;
use Illuminate\Support\ServiceProvider;

/** Binds dev-only fixture seams; nothing here is bound outside the local environment. */
final class LocalFixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $fixture = config('services.ohdear.import_fixture');
        if (! $this->app->environment('local') || ! is_string($fixture) || $fixture === '') {
            return;
        }
        $this->app->bind(OhDearClientFactory::class, static fn (): RecordedOhDearClientFactory => new RecordedOhDearClientFactory(base_path($fixture)));
    }
}
