<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDearImportMapping;
use Illuminate\Support\ServiceProvider;

final class ApplicationHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/application_health.php', 'application_health');
    }

    public function boot(CheckRegistry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(ApplicationHealthCheck::class, new OhDearImportMapping([
            'result_url', 'secret', 'headers', 'frequency_value', 'frequency_unit', 'failed_notification_threshold',
        ]));
    }
}
