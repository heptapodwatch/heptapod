<?php

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class RecordSchedulerTick
{
    public function __invoke(): void
    {
        Cache::store('redis')->forever(config()->string('heptapod.health.scheduler_cache_key'), CarbonImmutable::now()->toIso8601String());
    }
}
