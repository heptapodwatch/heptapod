<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Cron\CronExpression;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Contracts\OhDearImportMapping;
use Heptapod\Contracts\UpcomingEventSource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

final class CronServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CronTaskStore::class, DatabaseCronTaskStore::class);
        $this->app->tag([CronMonitorCounts::class], 'monitor-counts');
        $this->app->tag([CronUpcomingSource::class], UpcomingEventSource::class);
        $this->mergeConfigFrom(__DIR__.'/../config/cron.php', 'cron');
        $this->mergeConfigFrom(__DIR__.'/../config/notifications.php', 'notifications.event_types.cron');
    }

    public function boot(CheckRegistry $registry): void
    {
        $registry->register(CronCheckType::class, new OhDearImportMapping([], ImportCronDefinitions::class));
        Validator::extend('heptapod_cron_expression', static fn (string $attribute, mixed $value): bool => is_string($value) && CronExpression::isValidExpression($value));
        Validator::replacer('heptapod_cron_expression', static fn (): string => __('cron.invalid_expression'));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
