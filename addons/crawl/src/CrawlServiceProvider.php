<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\FindingReclassifier;
use Heptapod\Contracts\OhDearImportMapping;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

final class CrawlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crawl.php', 'heptapod.crawl');
        $this->app->bind(FindingReclassifier::class, CrawlReclassifier::class);
    }

    public function boot(CheckRegistry $registry): void
    {
        Validator::extend('crawl_whitelist_url', static fn (string $attribute, mixed $value): bool => CrawlWhitelist::valid($value));
        Validator::replacer('crawl_whitelist_url', static fn (string $message, string $attribute): string => __('validation.url', ['attribute' => $attribute]));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(BrokenLinksCheck::class, new OhDearImportMapping([...array_keys(BrokenLinksCheck::configSchema()->fields), 'frequency_value', 'frequency_unit', 'failed_notification_threshold']));
        $registry->register(MixedContentCheck::class, new OhDearImportMapping([]));
    }
}
