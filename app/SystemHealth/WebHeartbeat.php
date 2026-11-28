<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\InstanceAvailability;
use Heptapod\Contracts\InstanceServing;
use Illuminate\Support\Facades\Cache;

/**
 * The instance's serving period from its web's /up (HealthController): every /up outside maintenance continues it, and
 * maintenance or a gap longer than GapSeconds since the previous /up starts a new one. A shorter web restart goes unseen.
 */
final readonly class WebHeartbeat implements InstanceAvailability
{
    /** /up further apart than this (three healthcheck intervals) is a gap in which the instance could not serve. */
    public const int GapSeconds = 90;

    /** Without /up for this long the heartbeat is gone and counts as absent: it never stops a reader for good. */
    public const int TtlSeconds = 15 * 60;

    /**
     * Cache entry {seen_at, available_since} (ISO 8601, both null in maintenance). The key and shape are the ones the
     * servers add-on wrote before core owned the heartbeat, so an upgrade keeps the serving period it is in.
     */
    private const string Key = 'heptapod:servers:ingest';

    /** On every /up: maintenance mode still answers /up, but the instance does not serve then. */
    public function record(CarbonImmutable $now, bool $maintenance): void
    {
        if ($maintenance) {
            Cache::put(self::Key, ['seen_at' => null, 'available_since' => null], self::TtlSeconds);

            return;
        }
        $last = $this->read();
        $continues = $last !== null && $last['seen_at'] instanceof CarbonImmutable && $last['seen_at']->diffInSeconds($now) <= self::GapSeconds;
        $since = ($continues ? $last['available_since'] : null) ?? $now;
        Cache::put(self::Key, ['seen_at' => $now->utc()->toIso8601String(), 'available_since' => $since->utc()->toIso8601String()], self::TtlSeconds);
    }

    public function current(CarbonImmutable $now): InstanceServing
    {
        $last = $this->read();
        if ($last === null) {
            return InstanceServing::since(null);
        }

        return $last['seen_at'] instanceof CarbonImmutable && $last['seen_at']->diffInSeconds($now) <= self::GapSeconds
            ? InstanceServing::since($last['available_since']) : InstanceServing::down();
    }

    /** @return array{seen_at: ?CarbonImmutable, available_since: ?CarbonImmutable}|null null before the first /up */
    private function read(): ?array
    {
        /** @var array{seen_at: ?string, available_since: ?string}|null $last */
        $last = Cache::get(self::Key);

        return $last === null ? null : ['seen_at' => $last['seen_at'] === null ? null : CarbonImmutable::parse($last['seen_at']),
            'available_since' => $last['available_since'] === null ? null : CarbonImmutable::parse($last['available_since'])];
    }
}
