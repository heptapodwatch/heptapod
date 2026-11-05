<?php

declare(strict_types=1);

namespace Heptapod\Events;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionDecision;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\IncidentCause;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Illuminate\Support\Facades\DB;

final readonly class EventOutbox
{
    public function __construct(private NotificationSuppression $suppression, private EventFlapGroups $flaps) {}

    /** @param array<string, mixed> $payload */
    public function record(
        Check $check,
        CarbonImmutable $at,
        string $type,
        CheckResult $from,
        CheckResult $to,
        array $payload,
        ?Incident $incident = null,
        ?IncidentCause $cause = null,
        ?string $dedupeKey = null,
        int $version = 1,
        ?SuppressionSubject $subject = null,
        ?string $suppressionReason = null,
    ): ?Event {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Outbox writes require the recording transaction.');
        }
        $subject ??= new SuppressionSubject($check->id);
        if ($subject->checkId !== $check->id) {
            throw new \InvalidArgumentException('An addon event cannot target another check.');
        }
        if ($dedupeKey !== null && Event::query()->where('check_id', $check->id)->where('dedupe_key', $dedupeKey)->getQuery()->exists()) {
            return null;
        }
        $suppressionReason ??= UptimeDependencies::isGrouped($incident) ? UptimeDependencies::SuppressionReason : null;
        $decision = $suppressionReason === null ? $this->suppression->isSuppressed($subject, $at)
            : new SuppressionDecision(true, $suppressionReason, $at);
        $event = new Event([
            'site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident?->id,
            'occurred_at' => $at, 'type' => $type, 'from_result' => $from, 'to_result' => $to, 'cause' => $cause,
            'payload_version' => $version, 'payload' => $payload, 'dedupe_key' => $dedupeKey,
            'component_type' => $subject->componentType?->value, 'component_id' => $subject->componentId,
            'suppressed' => $decision->suppressed, 'suppression_reason' => $decision->reason,
            'suppression_evaluated_at' => $decision->evaluatedAt,
        ]);
        $site = $check->site ?? throw new \LogicException('A recorded event must have a site.');
        $event->setRelation('check', $check);
        $event->setRelation('site', $site);
        $event->redactPayload($site);
        // Recorders already hold the check lock, then the site lock. Grouping reads add no locks.
        $this->flaps->assign($event);
        $event->save();
        // Only a wake-up: the persisted unrouted row remains the delivery source of truth.
        DB::afterCommit(static fn () => event(new OutboxRecorded($event->id)));

        return $event;
    }
}
