<?php

declare(strict_types=1);

namespace Heptapod\Addons\Performance;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDearImportMapping;
use Illuminate\Support\ServiceProvider;

final class PerformanceServiceProvider extends ServiceProvider
{
    public function boot(CheckRegistry $registry): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $registry->register(PerformanceCheck::class, new OhDearImportMapping(['threshold_in_ms', 'change_percentage', 'frequency_value', 'frequency_unit', 'failed_notification_threshold']));
    }
}
