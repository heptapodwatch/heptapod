<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Contracts\TlsFailureReason;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\EventFacts;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Support\RedactionContext;

final readonly class CheckRunPresentations
{
    public function __construct(private CheckRegistry $registry, private CauseSummary $summaries) {}

    public function forRun(Check $check, ?CheckRun $run, ?MailEventFacts $event = null): ?CheckRunPresentationData
    {
        if (! $run instanceof CheckRun) {
            return null;
        }
        $summary = $this->summaries->project($check, $run);

        return $this->present($check, new CheckRunPresentationInput($run->result, $run->evidence, $run->metrics,
            $summary['key'] ?? throw new \LogicException('A retained run needs a summary.'), $summary['params'], $event));
    }

    public function atOpening(Incident $incident, ?MailEventFacts $event = null): ?CheckRunPresentationData
    {
        $check = $incident->check ?? throw new \LogicException('Incident check missing.');
        if (! in_array($check->type, $this->registry->keys(), true)) {
            return null;
        }
        $check->setRelation('site', $incident->site ?? throw new \LogicException('Incident site missing.'));
        $snapshot = $incident->evidence;
        $summary = $snapshot['summary'] ?? null;
        if ($summary === null) {
            return null;
        }
        $params = $snapshot['summary_params'] ?? [];
        $evidence = $snapshot['evidence'] ?? [];
        $metrics = $snapshot['metrics'] ?? null;
        $error = $snapshot['error'] ?? null;
        if (! is_string($summary) || ! is_array($params) || ! is_array($evidence) || ($metrics !== null && ! is_array($metrics))
            || ($error !== null && ! is_string($error))) {
            throw new \LogicException('Incident opening presentation facts are invalid.');
        }
        foreach ($params as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new \LogicException('Incident opening summary parameters must be scalar.');
            }
        }

        // A read-only adapter applies the same legacy error-summary rules without fetching or saving opening facts.
        return $this->forRun($check, new CheckRun([
            'result' => $error === null ? RunResult::Failed : RunResult::Errored,
            'evidence' => $evidence, 'metrics' => $metrics, 'summary' => $summary,
            'summary_params' => $params, 'error' => $error,
        ]), $event);
    }

    public function forInput(Check $check, CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        return $this->present($check, $run);
    }

    public function forIncident(Incident $incident, ?CheckRun $run, bool $heldAfterRecovery = false): ?CheckRunPresentationData
    {
        $check = $incident->check ?? throw new \LogicException('Incident check missing.');
        $check->setRelation('site', $incident->site ?? throw new \LogicException('Incident site missing.'));
        if ($incident->resolved_at === null && $incident->cause === IncidentCause::Errored && isset($incident->evidence['unverified_reason_key'])) {
            $summary = $this->summaries->project($check, new CheckRun([
                'summary' => $incident->evidence['unverified_reason_key'],
                'summary_params' => $incident->evidence['unverified_reason_params'],
                'evidence' => [],
            ]));

            return new CheckRunPresentationData([new CheckRunPresentationPartData($summary['key'] ?? throw new \LogicException('An unchecked incident needs a reason.'), $summary['params'])]);
        }
        // A retained incomplete good crawl must not replace an unresolved failure.
        $useCurrent = $incident->resolved_at === null && ! $heldAfterRecovery && ! UptimeDependencies::isGrouped($incident)
            && $run instanceof CheckRun && $run->ended_at->greaterThanOrEqualTo($incident->first_failure_at)
            && $run->result === ($incident->cause === IncidentCause::Failed ? RunResult::Failed : RunResult::Errored);

        return $useCurrent ? $this->forRun($check, $run) : $this->atOpening($incident);
    }

    public function forEvent(Event $event): ?CheckRunPresentationData
    {
        $check = $event->check;
        if ($check === null || $event->site === null) {
            return null; // Deleted checks/sites retain summary text, but cannot hydrate a presenter.
        }
        $check->setRelation('site', $event->site);
        $id = $event->payload['run_id'] ?? null;
        $endedAt = $event->payload['run_ended_at'] ?? null;
        $reference = null;
        if ($id !== null || $endedAt !== null) {
            if (! is_int($id) || ! is_string($endedAt)) {
                throw new \LogicException('Event run reference is invalid.');
            }
            $reference = new RunReference($id, CarbonImmutable::parse($endedAt));
            $run = CheckRun::forRun($reference)->where('check_id', $check->id)->first();
            if ($run !== null) {
                return $this->forRun($check, $run, new MailEventFacts($event->type, $event->payload, $check->id,
                    $event->component_type, $event->component_id, $reference));
            }
        }
        $mailFacts = new MailEventFacts($event->type, $event->payload, $check->id,
            $event->component_type, $event->component_id, $reference);

        $opening = $event->incident->evidence ?? [];
        // Only the same physical run may reuse opening facts; recovery must never inherit failure evidence.
        if ($id !== null && ($opening['run_id'] ?? null) === $id && is_string($opening['run_ended_at'] ?? null)
            && CarbonImmutable::parse($opening['run_ended_at'])->equalTo(CarbonImmutable::parse($endedAt))) {
            return $this->atOpening($event->incident ?? throw new \LogicException('Opening snapshot needs its incident.'), $mailFacts);
        }
        $facts = EventFacts::fromEvent($event);
        if ($facts->summaryKey === null) {
            return null;
        }
        $summary = $this->summaries->project($check, new CheckRun(['summary' => $facts->summaryKey, 'summary_params' => $facts->summaryParams, 'evidence' => []]));
        $key = $summary['key'] ?? throw new \LogicException('A retained event must have a summary.');

        $result = RunResult::tryFrom($event->to_result->value ?? '');
        if ($result !== null) {
            return $this->present($check, new CheckRunPresentationInput($result, [], null, $key, $summary['params'], $mailFacts));
        }

        if ($event->component_type !== null) {
            return null;
        }

        return new CheckRunPresentationData([new CheckRunPresentationPartData($key, $summary['params'])],
            names_check: $event->cause !== IncidentCause::Errored && $event->to_result !== CheckResult::Errored && $this->namesCheck($check->type, $key));
    }

    private function present(Check $check, CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        if (! in_array($check->type, $this->registry->keys(), true)) {
            return null;
        }
        $type = $this->registry->get($check->type);
        $presentation = $type instanceof CheckRunPresenter ? $type->presentRun($run) : null;
        if (! $presentation instanceof CheckRunPresentationData && $run->event?->componentType instanceof SuppressionComponent) {
            return null;
        }
        $presentation ??= new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary, $run->summaryParams)]);
        $context = RedactionContext::forSite($check->site ?? throw new \LogicException('Check site missing.'), $check, $run->evidence);
        $parts = [];
        foreach ($presentation->parts as $part) {
            /** @var array<string, string|int|float|bool|null> $params */
            $params = SecretRedactor::redactValues($part->params, $context->secrets, $context->urls);
            $parts[] = $part->withParams($params);
        }

        $changes = $this->redactParts($presentation->changes, $context);
        $details = $this->redactParts($presentation->details, $context);
        $first = $parts[0] ?? null;
        $tls = $first instanceof CheckRunPresentationPartData ? array_find(TlsFailureReason::cases(), static fn (TlsFailureReason $reason): bool => $first->key === 'causes.tls.'.$reason->value || str_starts_with($first->key, 'causes.tls.'.$reason->value.'_')) : null;
        $explanation = $tls === null ? null : new CheckRunPresentationPartData('checks.explanation.tls.'.$tls->value);
        $history = $presentation->history_label;
        if ($history instanceof CheckRunPresentationPartData) {
            /** @var array<string, string|int|float|bool|null> $historyParams */
            $historyParams = SecretRedactor::redactValues($history->params, $context->secrets, $context->urls);
            $history = new CheckRunPresentationPartData($history->key, $historyParams, $history->labels, untrusted: $history->untrusted, trusted: $history->trusted, lists: $history->lists);
        } else {
            $history = $tls === null ? null : new CheckRunPresentationPartData('checks.history.tls.'.$tls->value);
        }
        if (! $history instanceof CheckRunPresentationPartData && $first instanceof CheckRunPresentationPartData) {
            $label = match ($first->key) {
                'sitemap.presentation.http_status', 'sitemap.presentation.http_status_nonstandard' => 'checks.history.http_status',
                'sitemap.presentation.timeout' => 'checks.history.timeout',
                'sitemap.presentation.tls' => 'checks.history.tls',
                'sitemap.presentation.size_limit' => 'checks.history.size_limit',
                'lighthouse.presentation.below', 'lighthouse.presentation.below_and_dropped' => 'checks.history.score_below',
                'lighthouse.presentation.dropped' => 'checks.history.score_dropped',
                'broken_links.presentation.internal' => 'checks.history.broken_internal',
                'broken_links.presentation.external' => 'checks.history.broken_external',
                'uptime.status' => 'checks.history.http_status',
                'performance.median_above_threshold' => 'checks.history.performance_threshold',
                'performance.median_above_configured_range' => 'checks.history.performance_increased',
                'performance.confirming_warning' => 'checks.history.confirming_warning',
                'performance.confirming_recovery' => 'checks.history.confirming_recovery',
                'performance.succeeded' => 'checks.history.performance_average',
                'performance.recovered_below' => 'checks.history.performance_recovered',
                'performance.recovery_band' => 'checks.history.performance_recovery_band',
                default => null,
            };
            if ($label !== null) {
                $history = new CheckRunPresentationPartData($label, $first->params, $first->labels, untrusted: $first->untrusted, trusted: $first->trusted, lists: $first->lists);
            }
        }

        return new CheckRunPresentationData($parts, $explanation, $history,
            $run->result !== RunResult::Errored && array_any(array_slice($parts, 0, $presentation->headline_count),
                fn (CheckRunPresentationPartData $part): bool => $this->namesCheck($check->type, $part->key)),
            $presentation->headline_limit, $changes, $details);
    }

    /**
     * @param  list<CheckRunPresentationPartData>  $parts
     * @return list<CheckRunPresentationPartData>
     */
    private function redactParts(array $parts, RedactionContext $context): array
    {
        return array_map(static function (CheckRunPresentationPartData $part) use ($context): CheckRunPresentationPartData {
            /** @var array<string, string|int|float|bool|null> $params */
            $params = SecretRedactor::redactValues($part->params, $context->secrets, $context->urls);

            return $part->withParams($params);
        }, $parts);
    }

    private function namesCheck(CheckKey $check, string $key): bool
    {
        if (str_starts_with($key, 'application_health.presentation.failed') || str_starts_with($key, 'cron.presentation.') && $key !== 'cron.presentation.task') {
            return true;
        }
        // Generic crawl limits and lookup failures do not identify the check, even on subject-naming types.
        if (str_starts_with($key, 'causes.outbound.') || str_starts_with($key, 'causes.engine.')) {
            return false;
        }
        if (str_starts_with($key, 'crawl.') || str_starts_with($key, 'incidents.resolution.') || in_array($key, [
            'checks.engine.errored', 'causes.error',
            'broken_links.presentation.not_verifiable', 'sitemap.presentation.not_verifiable',
            'mixed_content.presentation.not_verifiable',
            'broken_links.presentation.unchecked', 'broken_links.presentation.page_limit',
            'broken_links.presentation.resource_limit', 'broken_links.presentation.time_limit',
            'broken_links.presentation.comparison_limit', 'broken_links.incomplete',
            'broken_links.incomplete_resources', 'broken_links.incomplete_other', 'broken_links.incomplete_rate_limited_urls',
            'broken_links.incomplete_time_limit', 'broken_links.comparison_limit',
            'mixed_content.incomplete', 'mixed_content.incomplete_resources', 'mixed_content.incomplete_other',
            'mixed_content.incomplete_time_limit', 'mixed_content.comparison_limit', 'mixed_content.incomplete_rate_limited_urls',
            'certificate_health.not_https', 'checks.presentation.domain_valid_until', 'domain.registry_does_not_publish',
        ], true)) {
            return false;
        }

        return match ($check) {
            CheckKey::BrokenLinks, CheckKey::MixedContent, CheckKey::Sitemap,
            CheckKey::CertificateHealth, CheckKey::Domain, CheckKey::Dns => true,
            CheckKey::Uptime, CheckKey::Performance, CheckKey::Lighthouse,
            CheckKey::Cron, CheckKey::ApplicationHealth => false,
        };
    }
}
