<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\InstanceAvailability;
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

    /** Cache entry {seen_at, since} (ISO 8601, both null in maintenance). */
    private const string Key = 'heptapod:instance:web_heartbeat';

    /** On every /up: maintenance mode still answers /up, but the instance does not serve then. */
    public function record(CarbonImmutable $now, bool $maintenance): void
    {
        if ($maintenance) {
            Cache::put(self::Key, ['seen_at' => null, 'since' => null], self::TtlSeconds);

            return;
        }
        $last = $this->read();
        $continues = $last !== null && $last['seen_at'] instanceof CarbonImmutable && $last['seen_at']->diffInSeconds($now) <= self::GapSeconds;
        $since = ($continues ? $last['since'] : null) ?? $now;
        Cache::put(self::Key, ['seen_at' => $now->utc()->toIso8601String(), 'since' => $since->utc()->toIso8601String()], self::TtlSeconds);
    }

    public function serving(CarbonImmutable $now): bool
    {
        $last = $this->read();

        return $last === null || ($last['seen_at'] instanceof CarbonImmutable && $last['seen_at']->diffInSeconds($now) <= self::GapSeconds);
    }

    public function servingSince(): ?CarbonImmutable
    {
        return $this->read()['since'] ?? null;
    }

    /** @return array{seen_at: ?CarbonImmutable, since: ?CarbonImmutable}|null null before the first /up */
    private function read(): ?array
    {
        /** @var array{seen_at: ?string, since: ?string}|null $last */
        $last = Cache::get(self::Key);

        return $last === null ? null : ['seen_at' => $last['seen_at'] === null ? null : CarbonImmutable::parse($last['seen_at']),
            'since' => $last['since'] === null ? null : CarbonImmutable::parse($last['since'])];
    }
}
