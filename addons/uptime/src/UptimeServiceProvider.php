<?php

declare(strict_types=1);

namespace Heptapod\Addons\Uptime;

use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\OhDearImportMapping;
use Illuminate\Support\ServiceProvider;

final class UptimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UptimeCheck::class, fn (): UptimeCheck => new UptimeCheck(
            $this->app->make(OutboundHttp::class),
            $this->app->bound(CertificateFetcher::class) ? $this->app->make(CertificateFetcher::class) : null,
        ));
    }

    public function boot(CheckRegistry $registry): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(UptimeCheck::class, new OhDearImportMapping(['http_verb', 'look_for_string', 'absent_string', 'expected_response_headers', 'response_body_assertions', 'payload', 'raw_payload', 'http_client_headers', 'timeout', 'valid_status_codes', 'max_redirect_count', 'expected_final_redirect_url', 'frequency_value', 'frequency_unit', 'failed_notification_threshold']));
    }
}
