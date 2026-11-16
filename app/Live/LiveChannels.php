<?php

declare(strict_types=1);

namespace Heptapod\Live;

use Carbon\CarbonImmutable;
use Heptapod\Enums\ClientScope;
use Heptapod\Events\LiveSignal;
use Heptapod\Models\User;
use Heptapod\Queries\SitesReader;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The live channels a viewer subscribes to, and when each last carried a change. A tab's first subscription
 * compares that stamp with its page's render time instead of reloading every page prop.
 */
final readonly class LiveChannels
{
    public function __construct(private SitesReader $sites) {}

    /** @return list<string> */
    public function forUser(User $user): array
    {
        if (! $user->hasAnyRole(['Owner', 'Admin', 'Member'])) {
            return [];
        }

        return $user->hasAnyRole(['Owner', 'Admin']) || $user->client_scope === ClientScope::All
            ? ['instance']
            : array_map(static fn (int $clientId): string => 'client.'.$clientId, $this->sites->clientIds($user));
    }

    /**
     * Called when the change commits, not when its broadcast runs: a stalled broadcasts queue must not hide it from
     * a tab that subscribes in between.
     */
    public function recordChange(LiveSignal $signal): void
    {
        $channels = array_map(static fn (PresenceChannel $channel): string => Str::chopStart($channel->name, 'presence-'), $signal->broadcastOn());
        Cache::putMany(array_fill_keys(array_map($this->key(...), $channels), CarbonImmutable::now()->getTimestampMs()));
    }

    /**
     * A channel without a stamp (no change recorded yet, or the cache was cleared) counts as changed: the tab reloads.
     *
     * @param  list<string>  $channels
     */
    public function changedSince(array $channels, int $sinceMs): bool
    {
        foreach (Cache::many(array_map($this->key(...), $channels)) as $changedAt) {
            // The Redis store returns stored integers as numeric strings.
            if (! is_numeric($changedAt) || (int) $changedAt >= $sinceMs) {
                return true;
            }
        }

        return false;
    }

    private function key(string $channel): string
    {
        return 'live:changed-at:'.$channel;
    }
}
