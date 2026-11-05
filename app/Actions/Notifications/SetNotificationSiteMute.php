<?php

declare(strict_types=1);

namespace Heptapod\Actions\Notifications;

use Heptapod\Actions\RequestRoutingReconciliation;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PUT|DELETE …/site-mutes/{site}: a permanent, audited "not this site" for one channel (design §1.1). Removing a mute
 * can newly reach an open outage on that site (§2.2). Mutes on client channels are limited to that client's sites.
 */
final readonly class SetNotificationSiteMute
{
    public function __construct(private RequestRoutingReconciliation $reconcile) {}

    public function __invoke(NotificationDestination $destination, Site $site, bool $muted, User $actor): void
    {
        if ($destination->recipient_kind === RecipientKind::Client && $site->client_id !== $destination->client_id) {
            throw ValidationException::withMessages(['site' => __('notifications.mute_other_client')]);
        }
        NotificationLocks::transaction(function () use ($destination, $site, $muted, $actor): void {
            // NotificationLocks: the actor (the mute row's actor_id key; profile deletion holds the user, then archives its
            // channels), the open episodes an unmute marks (incidents), then the channel.
            NotificationLocks::users([$actor->id]);
            if (! $muted) {
                $this->reconcile->lockEpisodes($destination, $site->id);
            }
            NotificationLocks::channels([$destination->id]);
            $locked = NotificationDestination::query()->findOrFail($destination->id);
            if ($locked->removed_at !== null) {
                throw ValidationException::withMessages(['site' => __('notifications.channel_removed')]);
            }
            $changed = $muted
                ? DB::table('notification_site_mutes')->insertOrIgnore(['destination_id' => $locked->id, 'site_id' => $site->id,
                    'actor_id' => $actor->id, 'created_at' => now()])
                : DB::table('notification_site_mutes')->where('destination_id', $locked->id)->where('site_id', $site->id)->delete();
            if ($changed === 0) {
                return;
            }
            if (! $muted) {
                $this->reconcile->forChannel($locked, $site->id);
            }
            activity('audit')->causedBy($actor)->performedOn($locked)->event($muted ? 'notification.site_muted' : 'notification.site_unmuted')
                ->withProperties(['site_id' => $site->id])->log($muted ? 'notification.site_muted' : 'notification.site_unmuted');
        });
    }
}
