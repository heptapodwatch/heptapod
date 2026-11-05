<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\ClaimSystemProbe;
use Heptapod\Actions\CompleteSystemProbe;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\SystemIssue;
use Illuminate\Support\Collection;

/**
 * Proves a dead transport works again by submitting its real recovery mail (design §3.4). Runs once per evaluation pass
 * inside the evaluation lock; a handshake proves nothing. Later failures are a new episode for the detector.
 */
final readonly class SystemTransportProbe
{
    /** A rejected probe recipient is tried again after this long even if its address did not change (a full mailbox heals). */
    public const int RejectedCooldownSeconds = 3600;

    public function __construct(private SystemIssueMailer $mailer, private ClaimSystemProbe $claim, private CompleteSystemProbe $complete, private ChannelRegistry $channels) {}

    public function run(SystemIssue $issue): void
    {
        if ($issue->kind !== SystemIssueKind::MailTransportDead || $issue->resolved_at !== null || ! SystemIssueMailer::canDeliver()) {
            return;
        }
        $event = $this->mailer->event($issue, 'system.issue_resolved', CarbonImmutable::now('UTC'), ['probe' => true]);
        $recipients = $this->mailer->recipients()->filter(static fn (NotificationDestination $channel): bool => $channel->channel === 'mail');
        $this->mailer->insertDeliveries($event, $recipients, ClaimNotificationDelivery::SystemProbeReason);
        $this->rearmRejected($event->id, $recipients);
        $probes = Delivery::query()->where('event_id', $event->id)->where('last_reason', ClaimNotificationDelivery::SystemProbeReason)->where('suppressed', false);
        $probes->getQuery()->orderBy('destination_id');
        foreach ($probes->get(['id']) as $probe) {
            $delivery = ($this->claim)($probe->id, $issue->id);
            if (! $delivery instanceof Delivery) {
                continue;
            }
            $channel = NotificationDestination::query()->findOrFail($delivery->destination_id);
            $result = $this->channels->send('mail', ChannelRecipient::forChannel($channel->destinationConfig()), $this->mailer->notification($event, $channel));
            $outcome = ($this->complete)($delivery->id, $delivery->claim_token ?? throw new \LogicException('Probe claim has no token.'), $result);
            if ($outcome !== 'rejected') {
                return;
            }
        }
    }

    /**
     * A rejection is terminal for that probe round, never for the episode: the round stays as history, and a new round
     * opens once the recipient's address changed or the cool-down passed. Otherwise a single rejected recipient would
     * keep a dead transport open forever after its mailbox is repaired.
     *
     * @param  Collection<int, NotificationDestination>  $recipients
     */
    private function rearmRejected(int $eventId, Collection $recipients): void
    {
        $now = CarbonImmutable::now('UTC');
        foreach ($recipients as $channel) {
            $latest = Delivery::query()->where('event_id', $eventId)->where('destination_id', $channel->id);
            $latest->getQuery()->orderByDesc('probe_round')->limit(1);
            $round = $latest->first();
            if (! $round instanceof Delivery || $round->suppression_reason !== 'smtp_recipient_rejected') {
                continue;
            }
            $rejectedAddress = $round->recipient_snapshot['address'] ?? null;
            $address = ChannelRecipient::forChannel($channel->destinationConfig())->routeNotificationForMail();
            $cooledDown = $round->suppression_evaluated_at->addSeconds(self::RejectedCooldownSeconds)->lessThanOrEqualTo($now);
            if ($address === $rejectedAddress && ! $cooledDown) {
                continue;
            }
            $this->mailer->insertDeliveries(Event::query()->findOrFail($eventId), new Collection([$channel]), ClaimNotificationDelivery::SystemProbeReason, $round->probe_round + 1);
        }
    }
}
