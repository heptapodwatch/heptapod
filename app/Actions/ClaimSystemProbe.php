<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\SystemIssueKind;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The only claim path for transport probe rows (last_reason system_probe; design §3.4). A probe is claimable while
 * its mail_transport_dead issue is open: pending, or sending with an expired lease after a crash.
 */
final readonly class ClaimSystemProbe
{
    public function __invoke(int $deliveryId, int $issueId): ?Delivery
    {
        return DB::transaction(function () use ($deliveryId, $issueId): ?Delivery {
            $issue = SystemIssue::query()->whereKey($issueId);
            NotificationLocks::forUpdate($issue->getQuery());
            $open = $issue->first();
            if (! $open instanceof SystemIssue || $open->resolved_at !== null || $open->kind !== SystemIssueKind::MailTransportDead) {
                return null;
            }
            $at = CarbonImmutable::now('UTC');
            // NotificationLocks: issue, then channel, then delivery. A busy channel is skipped for this pass like a busy
            // delivery; the probe tries the next recipient.
            if (! NotificationLocks::channels([NotificationLocks::channelOf($deliveryId)], skipLocked: true)) {
                return null;
            }
            $query = Delivery::awaitingDelivery()->whereKey($deliveryId)->where('last_reason', ClaimNotificationDelivery::SystemProbeReason)
                ->whereHas('event', static fn (EloquentBuilder $event): EloquentBuilder => $event->where('system_issue_id', $issueId)->where('type', 'system.issue_resolved'));
            $query->getQuery()->where(static fn (Builder $rows): Builder => $rows->where('state', DeliveryState::Pending->value)
                ->orWhere(static fn (Builder $expired): Builder => $expired->where('state', DeliveryState::Sending->value)->where('lease_until', '<=', $at->format('Y-m-d H:i:s.uP'))));
            NotificationLocks::forUpdateSkipLocked($query->getQuery());
            $delivery = $query->first();
            if (! $delivery instanceof Delivery) {
                return null;
            }
            // The guards of ClaimNotificationDelivery apply before any lease, also when an expired lease is reclaimed:
            // archiving leaves a live lease alone, so a crashed probe on an archived channel must be suppressed here.
            $channel = NotificationDestination::query()->with('user')->findOrFail($delivery->destination_id);
            $refused = $channel->removed_at === null ? SystemIssueMailer::ineligibility($channel)
                : ($channel->removed_reason === ArchiveNotificationChannel::RecipientDeleted ? 'recipient_deleted' : 'destination_archived');
            if ($refused !== null) {
                $delivery->update(['state' => DeliveryState::Pending, 'suppressed' => true, 'suppression_reason' => $refused, 'suppression_evaluated_at' => $at,
                    'claim_token' => null, 'lease_until' => null]);

                return null;
            }
            ClaimNotificationDelivery::startSending($delivery, $at);

            return $delivery;
        });
    }
}
