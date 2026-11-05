<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionDecision;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Events\Notifications\ExplanationExemplars;
use Heptapod\Events\Notifications\NotificationRecipients;
use Heptapod\Events\Notifications\NotificationTiming;
use Heptapod\Events\Notifications\RoutingContext;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\User;

/**
 * "Who gets this and why" (design §2.3). Routing steps 1 and 2 are the planner's own (NotificationRecipients,
 * NotificationTiming); an open uptime episode uses its real opening plus the read-only claim checks. Exemplars never go
 * through DeliveryAdmission, so recoveries without a real opener stay conditional.
 *
 * Statuses: now, at (deferred until due_at), digest (next digest at due_at), logged (mail only logged), conditional, not_sent.
 * Ledger and suppression reads are made once for all channels, never per channel.
 */
final readonly class NotificationExplanationReader
{
    public function __construct(private NotificationRecipients $recipients, private NotificationTiming $timing, private ExplanationExemplars $exemplars,
        private NotificationSuppression $suppression) {}

    /**
     * @return array{status: 'explained'|'not_applicable', category: string, exemplar: array{event_type: string, from_result: string|null, to_result: string|null}|null,
     *     recipients: list<array{channel_id: int, recipient_kind: string, label: string, channel: string, address: string|null, status: string, reason: string, due_at: string|null}>}
     */
    public function explain(User $viewer, Check $check, NotificationCategory $category, CarbonImmutable $at, ?CheckResult $recoveryOrigin = null): array
    {
        $site = $check->site ?? throw new \LogicException('An explained check must have a site.');
        $exemplars = $this->exemplars->for($check, $category);
        if ($recoveryOrigin instanceof CheckResult) {
            $exemplars = array_values(array_filter($exemplars, fn (Event $event): bool => $this->origin($event) === $recoveryOrigin));
        }
        if ($exemplars === []) {
            return ['status' => 'not_applicable', 'category' => $category->value, 'exemplar' => null, 'recipients' => []];
        }
        $incident = $this->openIncident($check);
        $event = $exemplars[0];
        $episode = null;
        if ($incident instanceof Incident && UptimeAlertEpisode::appliesTo($incident) && $category !== NotificationCategory::Recovery) {
            $opening = UptimeAlertEpisode::routingOpening($incident)->setRelation('incident', $incident)->setRelation('check', $check)->setRelation('site', $site);
            if (NotificationCategory::of($opening) === $category) {
                $event = $opening;
                $episode = $incident;
            }
        }
        $event->setRelation('site', $site)->setRelation('check', $check);
        $context = RoutingContext::fromEvent($event) ?? throw new \LogicException('An exemplar must be routable.');
        $candidates = $this->recipients->candidates($context);
        $eligible = array_values(array_map(static fn (array $candidate): int => $candidate['channel']->id,
            array_filter($candidates, static fn (array $candidate): bool => $candidate['excluded'] === null)));
        $schedules = NotificationSchedule::forChannels(array_column($candidates, 'channel'));
        // Planning's own event-level decisions, applied read-only (PlanNotificationEvent).
        $blocked = match (true) {
            IncidentResolutionReason::forCheck($check, $site) instanceof IncidentResolutionReason => 'monitoring_stopped',
            $category !== NotificationCategory::Recovery && UptimeDependencies::isGrouped($incident) => UptimeDependencies::SuppressionReason,
            default => null,
        };
        $ledger = $this->ledger($event, $category, $episode, $incident, $check, $blocked === null ? $eligible : [], $at);
        $recipients = [];
        foreach ($candidates as $candidate) {
            $channel = $candidate['channel'];
            [$status, $reason, $due] = match (true) {
                $candidate['excluded'] !== null => ['not_sent', $candidate['excluded'], null],
                $blocked !== null => ['not_sent', $blocked, null],
                default => $this->route($context, $schedules[$channel->id], $candidate['mode'], $at, $event, $episode, $channel, $ledger),
            };
            $visible = $channel->identityVisibleTo($viewer);
            $hidden = __('notifications.delivery_hidden_address');
            $hidden = is_string($hidden) ? $hidden : throw new \LogicException('Translations are text.');
            $recipients[] = ['channel_id' => $channel->id, 'recipient_kind' => $channel->recipient_kind->value, 'label' => $visible ? $channel->label : $hidden,
                'channel' => $channel->channel, 'address' => $channel->channel !== 'mail' ? null : ($visible ? $channel->destinationConfig()->string('email') : $hidden),
                'status' => $status, 'reason' => $reason, 'due_at' => $due?->toIso8601String()];
        }

        return ['status' => 'explained', 'category' => $category->value,
            'exemplar' => ['event_type' => $event->type, 'from_result' => $this->origin($event)?->value, 'to_result' => $event->to_result?->value],
            'recipients' => $recipients];
    }

    /**
     * Every ledger read the explanation needs, once for all eligible channels.
     *
     * Recoveries follow the admission rules of planning by kind: an incident recovery needs that incident's opener; a component or
     * warning recovery needs an opener in its own component episode (Delivery::recoveryEpisodeOpenings). An exemplar
     * recovery without a matching real failure stays conditional.
     *
     * @param  list<int>  $channelIds
     * @return array{recovery: array<int, string>|null, sent: array<int, true>, latest: array<int, Delivery>, decision: SuppressionDecision|null, opening_explanation: string|null, maintenance_admission: bool}
     */
    private function ledger(Event $event, NotificationCategory $category, ?Incident $episode, ?Incident $incident, Check $check, array $channelIds, CarbonImmutable $at): array
    {
        $ledger = ['recovery' => null, 'sent' => [], 'latest' => [], 'decision' => null, 'opening_explanation' => null, 'maintenance_admission' => false];
        if ($channelIds === []) {
            return $ledger;
        }
        if ($category === NotificationCategory::Recovery) {
            $ledger['recovery'] = $this->recoveries($event, $incident, $check, $channelIds, $at);
        }
        if ($episode instanceof Incident) {
            $ledger['sent'] = UptimeAlertEpisode::sentOpeningChannels($episode, $channelIds);
            $ledger['latest'] = UptimeAlertEpisode::latestOpenings($episode, $channelIds);
            $ledger['decision'] = $this->suppression->isSuppressed(new SuppressionSubject($episode->check_id), $at);
            $ledger['opening_explanation'] = UptimeAlertEpisode::openingSuppressionExplanation($episode, $event);
            $ledger['maintenance_admission'] = $event->suppression_reason === 'maintenance' && UptimeAlertEpisode::endedOpeningWindow($episode, $at) instanceof MaintenancePeriod;
        }

        return $ledger;
    }

    /**
     * Per channel: 'sent' (opener accepted), 'pending' (opener waiting) or 'none'. Channels absent from a resolved map have no opener.
     *
     * @param  list<int>  $channelIds
     * @return array<int, string>|null null when no real failure of this kind is open: the recovery stays conditional
     */
    private function recoveries(Event $exemplar, ?Incident $incident, Check $check, array $channelIds, CarbonImmutable $at): ?array
    {
        if ($exemplar->type === 'incident.resolved') {
            if (! $incident instanceof Incident || $this->origin($exemplar) !== $this->incidentResult($incident)) {
                return null;
            }
            // Same lookups as DeliveryAdmission: the accepted opener (episode-wide for uptime) and a pending, unsuppressed
            // opener of this incident (Delivery::latestIncidentOpening). A pause-discarded opener is not pending.
            ['alerted' => $sent, 'pending' => $pending] = Delivery::incidentOpenerChannels($incident, $channelIds);
            if (UptimeAlertEpisode::appliesTo($incident)) {
                $sent = UptimeAlertEpisode::sentOpeningChannels($incident, $channelIds);
            }
            $states = [];
            foreach ($channelIds as $id) {
                $states[$id] = isset($sent[$id]) ? 'sent' : (isset($pending[$id]) ? 'pending' : 'none');
            }

            return $states;
        }
        // A component or warning recovery: the same event planning would admit, against the check's real history.
        $recovery = $exemplar->replicate()->fill(['check_id' => $check->id, 'occurred_at' => $at]);
        $recovery->id = PHP_INT_MAX;
        $openings = Delivery::recoveryEpisodeOpeningsFor($recovery, $channelIds);
        if ($openings === []) {
            return null;
        }
        $states = [];
        foreach ($channelIds as $id) {
            $states[$id] = ($openings[$id]['sent'] ?? false) ? 'sent' : (($openings[$id]['kept'] ?? false) ? 'pending' : 'none');
        }

        return $states;
    }

    /**
     * @param  array{recovery: array<int, string>|null, sent: array<int, true>, latest: array<int, Delivery>, decision: SuppressionDecision|null, opening_explanation: string|null, maintenance_admission: bool}  $ledger
     * @return array{string, string, CarbonImmutable|null}
     */
    private function route(RoutingContext $context, NotificationSchedule $schedule, NotificationMode $mode, CarbonImmutable $at, Event $event,
        ?Incident $episode, NotificationDestination $channel, array $ledger): array
    {
        $planned = $this->timing->schedule($context, $schedule, $at, $mode === NotificationMode::Digest);
        $due = $planned['due'];
        if ($context->category === NotificationCategory::Recovery) {
            $state = $ledger['recovery'] === null ? null : ($ledger['recovery'][$channel->id] ?? 'none');
            if ($state === null) {
                return ['conditional', 'recovery_needs_opener', $due];
            }
            if ($state === 'pending') {
                return ['conditional', 'opener_pending', $due];
            }
            if ($state === 'none') {
                return ['not_sent', 'no_opener', null];
            }
        }
        if ($episode instanceof Incident) {
            $blocked = $this->episode($event, $channel, $ledger, $due);
            if ($blocked !== null) {
                return $blocked;
            }
        }

        return match (true) {
            $planned['digest'] => ['digest', 'digest', $due],
            $due->greaterThan($at) => ['at', $planned['reason'] === 'alert_delay' ? 'notifications.preview_alert_delay' : $planned['reason'], $due],
            $channel->channel === 'mail' && MailDeliveryMode::logOnly() => ['logged', 'mail_log_only', $due],
            default => ['now', 'now', $due],
        };
    }

    /**
     * The claim's read-only checks for an episode's real opening: already alerted, a recorder mute without a catch-up,
     * snooze or maintenance now, and an existing later due time.
     *
     * @param  array{recovery: array<int, string>|null, sent: array<int, true>, latest: array<int, Delivery>, decision: SuppressionDecision|null, opening_explanation: string|null, maintenance_admission: bool}  $ledger
     * @return array{string, string, CarbonImmutable|null}|null
     */
    private function episode(Event $opening, NotificationDestination $channel, array $ledger, CarbonImmutable &$due): ?array
    {
        if (isset($ledger['sent'][$channel->id])) {
            return ['not_sent', 'notifications.suppression.episode_already_alerted', null];
        }
        if ($ledger['opening_explanation'] !== null) {
            return ['not_sent', $ledger['opening_explanation'], null];
        }
        $delivery = $ledger['latest'][$channel->id] ?? null;
        $decision = $ledger['decision'] ?? throw new \LogicException('An episode explanation reads its suppression once.');
        if ($opening->suppression_reason === 'maintenance' && $decision->reason === 'maintenance') {
            return ['at', 'notifications.preview_opening_muted', null];
        }
        if (($opening->suppressed && ! $ledger['maintenance_admission']) || $delivery?->suppressed === true) {
            return ['not_sent', 'notifications.preview_opening_suppressed', null];
        }
        if ($decision->suppressed) {
            return ['at', 'notifications.preview_opening_muted', null];
        }
        if ($delivery instanceof Delivery && $delivery->next_attempt_at->greaterThan($due)) {
            $due = $delivery->next_attempt_at;
        }

        return null;
    }

    private function openIncident(Check $check): ?Incident
    {
        $query = Incident::query()->with('check')->where('check_id', $check->id);
        $query->getQuery()->whereNull('resolved_at')->orderByDesc('id');

        return $query->first();
    }

    /** The result an incident recovers from: Failed, or Errored for a check error. */
    private function incidentResult(Incident $incident): CheckResult
    {
        return $incident->cause === IncidentCause::Errored ? CheckResult::Errored : CheckResult::Failed;
    }

    private function origin(Event $event): ?CheckResult
    {
        $from = $event->payload['from_result'] ?? null;

        return is_string($from) ? CheckResult::tryFrom($from) : $event->from_result;
    }
}
