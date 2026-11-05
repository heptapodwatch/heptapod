<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\OhDearImportMapping;
use Illuminate\Support\ServiceProvider;

final class DnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dns.php', 'dns');
        $this->app->bind(DnsResolver::class, SpatieDnsResolver::class);
    }

    public function boot(CheckRegistry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(DnsCheck::class, new OhDearImportMapping(['extra_cnames', 'monitor_main_domain', 'ignored_record_types', 'check_nameservers_in_sync', 'frequency_value', 'frequency_unit', 'failed_notification_threshold']));
    }
}
