<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\DeliveryDecisionKind;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Servers\ServerIssueMailer;

/**
 * The send-time rule of a daily digest: the channel may receive it, its items (each by its own source's rule) may still
 * go, the address is verified and quiet hours are over. A digest has no site, so no site check applies to it.
 */
final readonly class DigestRule implements AlertRule
{
    public function __construct(private NotificationTiming $timing, private ServerIssueMailer $serverIssues, private NotificationMessage $messages) {}

    public function decide(Delivery $delivery, NotificationDestination $channel, Event $event, CarbonImmutable $at, bool $claimed): DeliveryDecision
    {
        $refusal = SiteAlertRule::access($event, $channel, $delivery) ?? $this->items($event, $channel, $at, $claimed);
        if ($refusal instanceof DeliveryDecision) {
            return $refusal;
        }
        if (! $channel->addressVerified()) {
            return DeliveryDecision::hold($at->addHour(), 'personal_email_unverified', setReason: true);
        }
        $due = $this->timing->afterQuietHours(NotificationSchedule::forChannel($channel), $at);

        return $due->greaterThan($at) ? DeliveryDecision::hold($due) : DeliveryDecision::send();
    }

    public function notification(Delivery $delivery, Event $event, NotificationDestination $channel): AlertNotification
    {
        return new AlertNotification($this->messages->build($event, $channel, $delivery->category), $delivery->event_id.':'.$channel->id);
    }

    /**
     * Why one digest item may not go to this channel now; null when it may. Digest assembly (EmitNotificationDigests),
     * the digest's claim and its send all filter items with it.
     */
    public function itemDecision(Delivery $source, Event $event, NotificationDestination $channel, CarbonImmutable $at): ?DeliveryDecision
    {
        if ($source->suppressed) {
            return DeliveryDecision::suppress($source->suppression_reason === 'site_unfollowed' ? 'site_unfollowed' : 'digest_sources_unavailable');
        }
        if ($event->server_issue_id !== null) {
            return $this->serverIssues->refusal($event, $channel, $at);
        }
        if ($event->site === null || $event->check_id === null) {
            return DeliveryDecision::suppress($event->site === null ? 'site_deleted' : 'monitoring_stopped');
        }

        return SiteAlertRule::access($event, $channel, $source);
    }

    /**
     * The digest's summary line, the same at assembly and rebuild: sites first, then servers, at most 20 names of at most
     * 80 characters, and how many more there are.
     *
     * @param  array<int|string, string>  $sites
     * @param  array<int|string, string>  $servers
     * @return array{sites: string, remaining_sites: int}
     */
    public static function labels(array $sites, array $servers): array
    {
        $labels = [...array_values($sites), ...array_values(array_unique($servers))];
        $shown = array_map(static fn (string $label): string => mb_strlen($label, 'UTF-8') > 80
            ? mb_substr($label, 0, 79, 'UTF-8').'…' : $label, array_slice($labels, 0, 20));

        return ['sites' => implode(', ', $shown), 'remaining_sites' => count($labels) - count($shown)];
    }

    /**
     * The digest's items, filtered with itemDecision(). At the claim an item that may not go is suppressed or, while
     * held, returned to the pool, and the payload is narrowed to the items that remain; at the send any change sends
     * the digest back to the claim. Null when the digest may go as it is.
     */
    private function items(Event $digest, NotificationDestination $channel, CarbonImmutable $at, bool $claimed): ?DeliveryDecision
    {
        $sourceIds = $digest->payload['delivery_ids'] ?? null;
        if (! is_array($sourceIds) || array_any($sourceIds, static fn (mixed $id): bool => ! is_int($id))) {
            throw new \LogicException('Digest source IDs are missing.');
        }
        $sources = Delivery::query()->with('event.site');
        $sources->getQuery()->whereIn('id', $sourceIds)->orderBy('id');
        $sites = [];
        $servers = [];
        $included = [];
        $excludedReasons = [];
        $changes = [];
        foreach ($sources->get() as $source) {
            $sourceEvent = $source->event ?? throw new \LogicException('Digest source event is missing.');
            $refusal = $this->itemDecision($source, $sourceEvent, $channel, $at);
            if ($refusal instanceof DeliveryDecision) {
                $excludedReasons[] = $refusal->reason ?? 'digest_sources_unavailable';
                if (! $source->suppressed && $source->state === DeliveryState::Pending) {
                    // A held item leaves this digest and waits for one after its hold.
                    $changes[] = [$source, $refusal->kind === DeliveryDecisionKind::Hold
                        ? ['digest_event_id' => null, 'next_attempt_at' => $refusal->until ?? $at, 'last_reason' => $refusal->reason]
                        : ['suppressed' => true, 'suppression_reason' => $refusal->reason, 'suppression_evaluated_at' => $at]];
                }

                continue;
            }
            $included[] = $source->id;
            if ($sourceEvent->server_issue_id !== null) {
                $server = $sourceEvent->server_name ?? throw new \LogicException('A server issue event needs its server name.');
                $servers[$server] = $server;
            } else {
                $site = $sourceEvent->site ?? throw new \LogicException('A digest item must keep its site.');
                $sites[$site->id] = $site->displayName();
            }
        }
        // The claim keeps the payload's order of items, so the digest is unchanged exactly when every item stays.
        $unchanged = $changes === [] && count($included) === count($sourceIds);
        if ($claimed) {
            return $unchanged ? null : DeliveryDecision::rebuild();
        }
        foreach ($changes as [$source, $attributes]) {
            $source->update($attributes);
            $source->announceChanges();
        }
        if ($included === []) {
            return DeliveryDecision::suppress(count(array_unique($excludedReasons)) === 1 ? $excludedReasons[0] : 'digest_sources_unavailable');
        }
        if (! $unchanged) {
            $digest->update(['payload' => [...$digest->payload, 'count' => count($included), ...self::labels($sites, $servers), 'delivery_ids' => $included]]);
        }

        return null;
    }
}
