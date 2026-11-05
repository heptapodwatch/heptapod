<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDearImportMapping;
use Heptapod\Contracts\UpcomingEventSource;
use Illuminate\Support\ServiceProvider;

final class CertificateHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/certificate_health.php', 'certificate_health');
        $this->mergeConfigFrom(__DIR__.'/../config/notifications.php', 'notifications.event_types.certificate_health');
        $this->app->bind(CertificateFetcher::class, StreamCertificateFetcher::class);
        $this->app->tag([CertificateMonitorCounts::class], 'monitor-counts');
        $this->app->tag([CertificateUpcomingSource::class], UpcomingEventSource::class);
    }

    public function boot(CheckRegistry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(CertificateHealthCheck::class, new OhDearImportMapping(['expires_soon_threshold_in_days', 'frequency_value', 'frequency_unit', 'failed_notification_threshold']));
    }
}
