<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Design §2.2: a routing change that can newly reach an open uptime episode marks it in the same transaction;
 * DispatchNotifications drains the marks in bounded batches. Callers: a channel created or resumed with an Outage,
 * Failure or Check error cell on, such a cell turning on, a removed site mute, a widened team scope.
 *
 * The marks are incident writes (NotificationLocks level 7), and planning holds such an incident while it locks the
 * channel. A caller that changes an existing channel therefore takes {@see self::lockEpisodes()} before the channel. A
 * newly created channel needs no such lock: nobody else can see its row before commit, so nobody waits on it.
 */
final readonly class RequestRoutingReconciliation
{
    /** The grid categories whose events open uptime episodes (today's site.down and check.failing). */
    public const array OpeningCategories = [NotificationCategory::Outage, NotificationCategory::Failure, NotificationCategory::CheckError];

    /**
     * Locks, in ascending id, every open uptime episode that {@see self::forChannel()} may mark for this channel. Called
     * before the channel lock, so it reads only what never changes: a client channel's client. A team scope can change
     * while the caller waits for the channel, so team and user channels lock the episodes of every client.
     */
    public function lockEpisodes(NotificationDestination $channel, ?int $siteId = null): void
    {
        $clientId = $channel->recipient_kind === RecipientKind::Client ? $channel->client_id : null;
        NotificationLocks::incidents($this->openEpisodes($clientId, $siteId)->getQuery()->pluck('id'));
    }

    public function forChannel(NotificationDestination $channel, ?int $siteId = null): void
    {
        $opens = DB::table('notification_preferences')->where('destination_id', $channel->id)
            ->whereIn('category', array_map(static fn (NotificationCategory $category): string => $category->value, self::OpeningCategories))
            ->where('mode', '<>', NotificationMode::Off->value)->exists();
        if (! $opens) {
            return;
        }
        $clientId = $channel->recipient_kind === RecipientKind::Client ? $channel->client_id : $channel->scope_client_id;
        $this->openEpisodes($clientId, $siteId)->getQuery()
            ->update(['routing_reconcile_requested_at' => DB::raw('clock_timestamp()')]);
    }

    /** @return Builder<Incident> */
    private function openEpisodes(?int $clientId, ?int $siteId): Builder
    {
        $sites = DB::table('sites')->select('id');
        if ($siteId !== null) {
            $sites->where('id', $siteId);
        }
        if ($clientId !== null) {
            $sites->where('client_id', $clientId);
        }
        $incidents = Incident::query();
        $incidents->getQuery()->whereNull('resolved_at')->whereNotNull('alert_episode_started_at')
            ->whereIn('site_id', $sites)
            ->whereIn('check_id', DB::table('checks')->select('id')->where('type', CheckKey::Uptime->value));

        return $incidents;
    }
}
