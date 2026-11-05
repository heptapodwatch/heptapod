<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\OhDearImportMapping;
use Illuminate\Support\ServiceProvider;

final class LighthouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lighthouse.php', 'lighthouse');
        $this->app->bind(HttpChromiumRenderer::class, fn (): HttpChromiumRenderer => new HttpChromiumRenderer(
            $this->app->make(OutboundHttp::class),
            $this->app->bound(CertificateFetcher::class) ? $this->app->make(CertificateFetcher::class) : null,
        ));
        $this->app->bind(ChromiumRenderer::class, HttpChromiumRenderer::class);
    }

    public function boot(CheckRegistry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(LighthouseCheck::class, new OhDearImportMapping([
            'continent', 'device_emulation', 'cpu_slowdown_modifier', 'notification_settings', 'http_client_headers',
            'frequency_value', 'frequency_unit', 'failed_notification_threshold',
        ]));
    }
}
