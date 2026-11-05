<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDearImportMapping;
use Illuminate\Support\ServiceProvider;

final class SitemapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sitemap.php', 'sitemap');
    }

    public function boot(CheckRegistry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(SitemapCheck::class, new OhDearImportMapping(['path', 'speed', 'frequency_value', 'frequency_unit', 'failed_notification_threshold']));
    }
}
