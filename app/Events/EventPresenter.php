<?php

declare(strict_types=1);

namespace Heptapod\Events;

use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Contracts\SummaryParameters;
use Heptapod\Data\Ui\EventData;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\SiteIconBacking;
use Heptapod\Enums\SiteStatus;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Support\RedactionContext;

final readonly class EventPresenter
{
    public function __construct(private NotificationEventTypes $types, private CauseSummary $summaries, private CheckRunPresentations $presentations) {}

    public function present(Event $event, ?CheckRun $run = null, bool $describeResultChange = false): EventData
    {
        // A deleted site is a valid audit state. Snapshot identity has no navigable relation.
        $site = $event->site;
        $label = $site?->displayName() ?? $event->site_name ?? throw new \LogicException('A timeline event must retain its site label.');
        $clientName = $site === null
            ? ($event->client?->is_internal === true ? $event->client->displayName() : $event->client_name ?? throw new \LogicException('A timeline event must retain its client name.'))
            : ($site->client ?? throw new \LogicException('A timeline site must have a client.'))->displayName();
        $contextSite = $site ?? new Site(['friendly_name' => $label,
            'url' => $event->site_url ?? throw new \LogicException('A deleted-site event must retain its URL.')]);
        $severity = $event->type === 'check.result_changed' && $event->to_result === CheckResult::Succeeded
            && in_array($event->from_result, [CheckResult::Warning, CheckResult::Failed, CheckResult::Errored], true)
            ? AlertSeverity::Recovered : $this->types->severity($event);
        $facts = EventFacts::fromEvent($event, $run);
        $resolutionKey = $facts->summaryParams['resolution_key'] ?? null;
        if ($resolutionKey !== null && ! is_string($resolutionKey)) {
            throw new \LogicException('Retained resolution key must be text.');
        }
        $administrativeResolution = $event->type === 'incident.resolved' && $resolutionKey !== null;
        $duration = $severity === AlertSeverity::Recovered && ! $administrativeResolution ? $facts->durationSeconds : null;
        $time = $severity === AlertSeverity::Recovered ? $facts->time : $event->occurred_at;
        $summary = null;
        if ($facts->summaryKey !== null) {
            $check = $event->check;
            if ($check !== null) {
                $check->setRelation('site', $contextSite);
                $cause = $this->summaries->project($check, new CheckRun(['summary' => $facts->summaryKey, 'summary_params' => $facts->summaryParams, 'evidence' => []]));
            } else {
                // Recorder payloads are already redacted; a deleted check has no live configuration to consult.
                $context = RedactionContext::forSite($contextSite, null);
                $params = SecretRedactor::redactStringValues($facts->summaryParams, $context);
                $cause = EngineMessage::normalize($facts->summaryKey, $params);
            }
            $summary = __($cause['key'] ?? throw new \LogicException('A retained event must have a summary.'), SummaryParameters::localized($cause['params'], app()->getLocale(), wholeMilliseconds: true));
            if (! is_string($summary)) {
                throw new \LogicException('Event summary translation must be text.');
            }
        }
        $changes = $event->payload['changes'] ?? [];
        $count = 0;
        foreach (is_array($changes) ? $changes : [] as $change) {
            if (is_array($change)) {
                $count += count(is_array($change['added'] ?? null) ? $change['added'] : []) + count(is_array($change['removed'] ?? null) ? $change['removed'] : []);
            }
        }
        $durationText = $duration === null ? null : implode(' ', array_filter([
            intdiv($duration, 60) > 0 ? intdiv($duration, 60).'m' : null,
            $duration % 60 > 0 ? ($duration % 60).'s' : null,
        ]));
        $resultChange = $describeResultChange && $event->type === 'check.result_changed' && $event->from_result !== null && $event->to_result !== null;
        $resultLabel = static fn (CheckResult $result): string => __(match ($result) {
            CheckResult::Succeeded => 'OK', CheckResult::Pending => 'Pending',
            CheckResult::Failed => SiteStatus::forIncident($event->check->type ?? $event->check_type
                ?? throw new \LogicException('A result-change event must retain its check type.'), IncidentCause::Failed)->labelKey(),
            CheckResult::Errored => SiteStatus::CheckError->labelKey(), CheckResult::Warning => SiteStatus::Warning->labelKey(),
        });
        $headline = null;
        $presentation = null;
        $changeBearingIncident = $run instanceof CheckRun && ($run->evidence['presentation_events'] ?? []) !== []
            && in_array($event->type, ['incident.opened', 'incident.cause_changed'], true);
        if ($event->check !== null && ($event->component_type !== null || $changeBearingIncident || (! str_starts_with($event->type, 'incident.') && ! str_starts_with($event->type, 'check.')))) {
            $event->check->setRelation('site', $contextSite);
            $result = RunResult::tryFrom($event->to_result->value ?? '');
            $mailFacts = new MailEventFacts($event->type, $event->payload, $event->check->id, $event->component_type, $event->component_id, $run?->reference());
            $presentation = $run instanceof CheckRun ? $this->presentations->forRun($event->check, $run, $mailFacts)
                : ($result !== null && $facts->summaryKey !== null ? $this->presentations->forInput($event->check,
                    new CheckRunPresentationInput($result, [], null, $facts->summaryKey, $facts->summaryParams, $mailFacts)) : null);
            if ($presentation instanceof CheckRunPresentationData) {
                $headline = CheckHeadline::format($presentation, app()->getLocale());
            }
        }
        if ($event->component_type !== null && $headline === null) {
            $summary = null;
        }
        $message = match (true) {
            $administrativeResolution => __($resolutionKey),
            $event->type === 'check.findings_reclassified' => __('events.findings_reclassified', [
                'count' => is_int($event->payload['count'] ?? null) ? $event->payload['count'] : throw new \LogicException('A reclassification note needs its count.'),
                'version' => is_int($event->payload['policy_version'] ?? null) ? $event->payload['policy_version'] : throw new \LogicException('A reclassification note needs its policy version.')]),
            $resultChange => __('events.result_changed', ['from' => $resultLabel($event->from_result), 'to' => $resultLabel($event->to_result)]),
            $headline !== null => $headline,
            $event->component_type !== null && ($facts->summaryParams['presentation'] ?? null) === 'direct' && $summary !== null => $summary,
            // A resolution with unverifiable links must not read as a plain recovery.
            $event->type === 'incident.resolved' && in_array($facts->summaryKey, ['broken_links.no_verified_broken_one', 'broken_links.no_verified_broken_other', 'sitemap.no_verified_issues_one', 'sitemap.no_verified_issues_other'], true) => $summary,
            $duration !== null => __('events.recovered_after', ['duration' => $durationText === '' ? '0s' : $durationText]),
            $severity === AlertSeverity::Recovered => __('events.recovered'),
            $event->type === 'dns.records_changed' => __('events.dns_changed', ['count' => $count]),
            ($facts->summaryParams['presentation'] ?? null) === 'direct' && $summary !== null => $summary,
            $severity === AlertSeverity::Error && $summary !== null => __('events.error_details', ['reason' => $summary]),
            $severity === AlertSeverity::Error => __('events.check_error'),
            $this->types->key($event) === 'site.down' || ($event->type === 'maintenance.catch_up' && $severity === AlertSeverity::Down && ($event->check->type ?? $event->check_type) === CheckKey::Uptime) => __('events.down'),
            $severity === AlertSeverity::Down => __('events.failed'),
            $severity === AlertSeverity::Warning && $summary !== null => __('events.warning_details', ['reason' => $summary]),
            $severity === AlertSeverity::Warning => __('events.warning'),
            default => __('events.changed'),
        };
        if (! is_string($message)) {
            throw new \LogicException('An event message must be text.');
        }

        if ($event->type === 'maintenance.catch_up') {
            $message = __('events.maintenance_catch_up', ['reason' => $message]);
        }
        if ($event->type === 'incident.cause_changed' && $headline === null) {
            $message = __('events.cause_changed', ['reason' => $summary ?? $message]);
        }
        $params = [];
        if ($event->component_type !== null) {
            $name = $facts->componentName ?? throw new \LogicException('A component event must have an identity.');
            $check = $event->check;
            $context = RedactionContext::forSite($contextSite, $check);
            $params = ['component' => SecretRedactor::redact($name, $context->secrets, $context->urls)];
            if ($headline === null) {
                $message = SecretRedactor::redact(__('events.component_status', [...$params, 'message' => $message]), $context->secrets, $context->urls);
            }
        }

        if (($facts->summaryParams['presentation'] ?? null) === 'direct') {
            $params['presentation'] = 'direct';
        }
        if ($resultChange) {
            $params['result_change'] = true;
        }
        $siteLabel = $site === null ? __('events.deleted_site', ['label' => $label]) : $label;
        if (! is_string($siteLabel)) {
            throw new \LogicException('Event site label translation must be text.');
        }

        return new EventData($event->id, $severity, $site?->id, $siteLabel, $event->incident_id,
            $event->check->type ?? $event->check_type, $event->type, $time->toIso8601String(), $message, $params, $duration,
            $clientName, $site?->iconUrl(), $site === null ? SiteIconBacking::None : $site->icon_backing, $site === null ? $event->client_id : $site->client_id, $presentation->changes ?? [],
            is_int($event->payload['run_id'] ?? null) ? $event->payload['run_id'] : null);
    }
}
