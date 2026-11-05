<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\OhDearImportMapping;
use Heptapod\Contracts\UpcomingEventSource;
use Illuminate\Support\ServiceProvider;
use Klkvsk\Whoeasy\Registry\ServerRegistry;
use Klkvsk\Whoeasy\Whoeasy;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/domain.php', 'domain');
        $this->app->bind(DomainRegistrationLookup::class, RdapWhoisLookup::class);
        $this->app->tag([DomainMonitorCounts::class], 'monitor-counts');
        $this->app->singleton(ServerRegistry::class, fn (): ServerRegistry => ServerRegistry::getInstance());
        $this->app->bind(Whoeasy::class, fn (): Whoeasy => new Whoeasy(registry: $this->app->make(ServerRegistry::class), whoisClient: $this->app->make(BoundedWhoisClient::class)));
        $this->app->tag([DomainUpcomingSource::class], UpcomingEventSource::class);
    }

    public function boot(CheckRegistry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(DomainCheck::class, new OhDearImportMapping(['expires_soon_threshold_in_days', 'frequency_value', 'frequency_unit', 'failed_notification_threshold']));
    }
}
