<?php

declare(strict_types=1);

namespace Heptapod\Addons\Performance;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\PerformanceResponseTimeWindow;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\SummaryParameters;

final class PerformanceCheck implements CheckRunPresenter, CheckType, MailEvidenceProvider
{
    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        $improved = $run->eventPayload('performance.improved');
        if ($improved !== null) {
            $baseline = $improved['baseline'] ?? null;
            $median = $improved['median'] ?? null;
            $change = $improved['change_percentage'] ?? null;
            if ((! is_int($baseline) && ! is_float($baseline)) || (! is_int($median) && ! is_float($median)) || (! is_int($change) && ! is_float($change))) {
                throw new \LogicException('A performance improvement needs its retained baseline, median and change.');
            }
            $seconds = static fn (int|float $ms): float => round($ms / 1000, $ms < 1000 ? 2 : 1);

            return new CheckRunPresentationData([new CheckRunPresentationPartData('performance.presentation.improved',
                ['from' => $seconds($baseline), 'to' => $seconds($median), 'change' => (int) round($change)])]);
        }
        if (! $run->event instanceof MailEventFacts) {
            return null;
        }
        $short = match ($run->summary) {
            'performance.median_above_configured_range' => 'performance.presentation.relative_short',
            'performance.median_above_threshold' => 'performance.presentation.threshold_short',
            'performance.recovered_below' => 'performance.presentation.recovered_short',
            default => null,
        };

        return new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary, $run->summaryParams, shortKey: $short)]);
    }

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'performance.mail_event.check.failing',
            'check.warning' => 'performance.mail_event.check.warning',
            'check.recovered' => 'performance.mail_event.check.recovered',
            'performance.improved' => 'performance.mail_event.performance.improved',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'performance.improved' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Changed, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $recoveryRows = [];
        if ($run->summary === 'performance.recovered_below') {
            foreach (['median' => 'performance.mail_median', 'recovery_below' => 'performance.mail_recovers_below'] as $param => $label) {
                $value = $run->summaryParams[$param] ?? null;
                if (! is_int($value) && ! is_float($value)) {
                    throw new \LogicException('Performance recovery needs its retained median and bound.');
                }
                $recoveryRows[] = ['label' => __($label, locale: $locale), 'value' => new Cell(SummaryParameters::localizedMeasurements([$param => $value], $locale)[$param].' ms', mono: true)];
            }
        }
        if ($run->metrics === null) {
            return $recoveryRows === [] ? [] : [new FactsBlock(__('performance.mail_response', locale: $locale), $recoveryRows)];
        }
        $metrics = PerformanceMetrics::from($run->metrics);
        $rows = [
            ...$recoveryRows,
            ['label' => __('performance.mail_average', locale: $locale), 'value' => new Cell(SummaryParameters::localizedMeasurements(['value' => $metrics->average_response_time_in_ms], $locale)['value'].' ms', mono: true, tone: $run->result === RunResult::Warning ? MailTone::Warning : null)],
            ['label' => __('performance.mail_samples', locale: $locale), 'value' => new Cell((string) $metrics->sample_count)],
        ];
        if ($metrics->previous_average_response_time_in_ms !== null) {
            $rows[] = ['label' => __('performance.mail_baseline', locale: $locale), 'value' => new Cell(SummaryParameters::localizedMeasurements(['value' => $metrics->previous_average_response_time_in_ms], $locale)['value'].' ms', mono: true)];
        }
        if ($metrics->change_percentage !== null) {
            $rows[] = ['label' => __('performance.mail_change', locale: $locale), 'value' => new Cell(SummaryParameters::localizedMeasurements(['value' => $metrics->change_percentage], $locale)['value'].' %', mono: true)];
        }

        return [new FactsBlock(__('performance.mail_response', locale: $locale), $rows)];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('performance.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        return new CheckDefinition(
            CheckKey::Performance, 'checks.performance.label', 'timer', 'checks-fast', 10, 60, 1, ExecutionMode::Derived,
            new Schema([
                'threshold_in_ms' => new Field(FieldType::Integer, ['sometimes', 'min:0'], presentation: new FieldPresentation('Threshold', 'performance.threshold_help'), default: 2000),
                'change_percentage' => new Field(FieldType::Integer, ['sometimes', 'min:0'], presentation: new FieldPresentation('performance.minimum_increase', 'performance.minimum_increase_help'), default: 50),
                'recovery_margin_percentage' => new Field(FieldType::Integer, ['sometimes', 'between:0,20'], presentation: new FieldPresentation('performance.recovery_margin', 'performance.recovery_margin_help'), default: 5),
            ]),
            new Schema([
                'average_response_time_in_ms' => new Field(FieldType::Number, ['required', 'min:0']),
                'previous_average_response_time_in_ms' => new Field(FieldType::Number, ['present', 'nullable', 'min:0']),
                'change_percentage' => new Field(FieldType::Number, ['present', 'nullable']),
                'sample_count' => new Field(FieldType::Integer, ['required', 'min:1']),
                'previous_sample_count' => new Field(FieldType::Integer, ['required', 'min:0']),
                'consecutive_warning_samples' => new Field(FieldType::Integer, ['required', 'between:0,3']),
                'consecutive_good_samples' => new Field(FieldType::Integer, ['required', 'between:0,3']),
                'consecutive_improved_samples' => new Field(FieldType::Integer, ['required', 'between:0,3']),
                'consecutive_normal_samples' => new Field(FieldType::Integer, ['required', 'between:0,3']),
            ]),
            emittedEvents: ['performance.improved'],
            minimumIncreaseInMs: 200,
        );
    }

    public function persistence(): ?RunPersistence
    {
        return null;
    }

    public function execute(CheckContext $context): CheckOutcome|NoResultReason
    {
        $definition = $this->definition();
        $defaults = $definition->configSchema->defaults();
        $minimumIncreaseInMs = $definition->minimumIncreaseInMs
            ?? throw new \LogicException('Performance needs its minimum increase.');
        $history = $context->history ?? throw new \LogicException('Performance requires the history reader.');
        $currentFrom = $context->startedAt->subSeconds(PerformanceResponseTimeWindow::seconds($context->uptimeIntervalSeconds));
        $windows = [];
        $hour = $context->startedAt->utc()->startOfHour();
        for ($day = 1; $day <= 7; $day++) {
            $from = $hour->subDays($day);
            $windows[] = ['from' => $from, 'until' => $from->addHour()];
        }
        $current = [];
        $previous = [];
        $days = [];
        foreach ($history->read($context->siteId, CheckKey::Uptime, $currentFrom, $context->startedAt, 10000, $windows) as $run) {
            $seconds = $run->metrics['total_time_in_seconds'] ?? null;
            if (! in_array($run->result, [RunResult::Succeeded, RunResult::Warning], true) || (! is_int($seconds) && ! is_float($seconds)) || $seconds < 0) {
                continue;
            }
            if ($run->run->endedAt->greaterThanOrEqualTo($currentFrom)) {
                $current[] = $seconds * 1000;
            } else {
                $previous[] = $seconds * 1000;
                $days[$run->run->endedAt->utc()->toDateString()] = true;
            }
        }
        if ($current === []) {
            return $context->uptimeIntervalSeconds > PerformanceResponseTimeWindow::MaximumUptimeIntervalSeconds
                ? NoResultReason::PerformanceUptimeIntervalTooLong
                : NoResultReason::PerformanceNoSamples;
        }
        $average = array_sum($current) / count($current);
        sort($current, SORT_NUMERIC);
        $median = $this->percentile($current, 0.5);
        // Sparse history cannot establish a usual response range; the absolute threshold still applies.
        sort($previous, SORT_NUMERIC);
        $baseline = count($previous) >= 9 && count($days) >= 3 ? $this->percentile($previous, 0.5) : null;
        $p90 = $baseline !== null ? $this->percentile($previous, 0.9) : null;
        $change = count($current) >= 3 && $baseline !== null && $baseline > 0
            ? ($median - $baseline) / $baseline * 100 : null;
        $threshold = $context->config['threshold_in_ms'] ?? $defaults['threshold_in_ms'];
        if (! is_int($threshold) && ! is_float($threshold)) {
            throw new \LogicException('Performance threshold must be numeric.');
        }
        $slow = $median > $threshold;
        $minimumIncrease = $context->config['change_percentage'] ?? $defaults['change_percentage'];
        if (! is_int($minimumIncrease) || $minimumIncrease < 0) {
            throw new \LogicException('Performance minimum increase must be a nonnegative integer.');
        }
        $relativeThreshold = $baseline !== null && $p90 !== null
            ? max($p90, $baseline * (1 + $minimumIncrease / 100)) : null;
        $changed = $relativeThreshold !== null && $change !== null && $median > $relativeThreshold && $median - $baseline >= $minimumIncreaseInMs;
        $summary = $slow ? 'performance.median_above_threshold' : ($changed ? 'performance.median_above_configured_range' : 'performance.succeeded');
        $params = ['average' => round($average, 2)];
        if ($slow) {
            $params += ['median' => round($median, 2), 'threshold' => $threshold];
        } elseif ($changed) {
            $params += ['median' => round($median, 2), 'baseline' => round($baseline, 2),
                'p90' => round($p90, 2), 'delta' => round($median - $baseline, 2),
                'minimum_increase' => $minimumIncrease, 'relative_threshold' => round($relativeThreshold, 2)];
        }

        $wasWarning = $context->previousResult?->result === RunResult::Warning;
        $margin = $context->config['recovery_margin_percentage'] ?? $defaults['recovery_margin_percentage'];
        if (! is_int($margin) || $margin < 0 || $margin > 20) {
            throw new \LogicException('Performance recovery margin must be an integer between 0 and 20.');
        }
        // Multiply before dividing: 1 - 7/100 is not exact in floating point, so 2000 * (1 - 0.07) is 1859.999…
        // and a median of exactly 1860 would miss the "at or below" bound the UI shows.
        $recoveryBound = static fn (float|int $value): float|int => $value * (100 - $margin) / 100;
        $absoluteRecoveryBelow = $recoveryBound($threshold);
        $relativeRecoveryBelow = $relativeThreshold !== null && $change !== null
            ? $recoveryBound(max($relativeThreshold, $baseline + $minimumIncreaseInMs)) : null;
        // Keep the relative entry rule's strict threshold and inclusive 200 ms floor when the margin is zero.
        $relativeGood = $relativeThreshold === null || $change === null
            || $median <= $recoveryBound($relativeThreshold)
            || $median < $recoveryBound($baseline + $minimumIncreaseInMs);
        $warningSample = $wasWarning ? $median > $absoluteRecoveryBelow || ! $relativeGood : $slow || $changed;
        $recoveryBelow = $relativeRecoveryBelow === null ? $absoluteRecoveryBelow : min($absoluteRecoveryBelow, $relativeRecoveryBelow);
        $previousMetrics = $context->previousResult?->metrics === null ? null : PerformanceMetrics::from($context->previousResult->metrics);
        $warningSamples = $warningSample ? min(3, ($previousMetrics->consecutive_warning_samples ?? 0) + 1) : 0;
        $goodSamples = $warningSample ? 0 : min(3, ($previousMetrics->consecutive_good_samples ?? 0) + 1);
        $warning = $warningSamples === 3 || ($wasWarning && $goodSamples < 3);
        // A speed-up confirms like a warning but only outside one, so a recovery is never an improvement. The third fast
        // evaluation emits once; the latch (3) holds until three evaluations in a row are no longer fast.
        $fast = ! $wasWarning && ! $warningSample && $change !== null
            && $median < $baseline * (100 - $minimumIncrease) / 100 && $baseline - $median >= $minimumIncreaseInMs;
        $previousImproved = $previousMetrics->consecutive_improved_samples ?? 0;
        $normalSamples = ! $fast && $previousImproved === 3 ? min(3, ($previousMetrics->consecutive_normal_samples ?? 0) + 1) : 0;
        $improvedSamples = $fast ? min(3, $previousImproved + 1) : ($previousImproved === 3 && $normalSamples < 3 ? 3 : 0);
        $events = [];
        if ($fast && $previousImproved === 2) {
            $improvement = ['baseline' => round($baseline, 2), 'median' => round($median, 2), 'change_percentage' => round($change, 2)];
            $events[] = new AddonEvent('performance.improved', 'performance:'.$context->checkId.':'.$context->runId, 1, $improvement, presentationParams: $improvement);
        }
        if (! $warning && $warningSamples > 0) {
            $summary = 'performance.confirming_warning';
            $params = ['average' => round($average, 2), 'count' => $warningSamples];
        } elseif ($warning && $goodSamples > 0) {
            $summary = 'performance.confirming_recovery';
            $params = ['average' => round($average, 2), 'count' => $goodSamples];
        } elseif ($wasWarning && ! $warning) {
            $summary = 'performance.recovered_below';
            $params = ['average' => round($average, 2), 'median' => round($median, 2), 'recovery_below' => round($recoveryBelow, 2)];
        } elseif ($wasWarning && ! $slow && ! $changed && $warningSample) {
            $summary = 'performance.recovery_band';
            $params = ['average' => round($average, 2), 'median' => round($median, 2), 'recovery_below' => round($recoveryBelow, 2)];
            if (! $relativeGood) {
                $params += ['baseline' => round($baseline, 2), 'p90' => round($p90, 2), 'relative_threshold' => round($relativeThreshold, 2)];
            }
        }

        return new CheckOutcome(
            $warning ? RunResult::Warning : RunResult::Succeeded,
            $summary,
            $params,
            new PerformanceMetrics($average, $baseline, $change, count($current), count($previous), $warningSamples, $goodSamples, $improvedSamples, $normalSamples),
            events: $events,
        );
    }

    /** @param non-empty-list<float> $sortedSamples Numeric ascending order; linear interpolation matches percentile_cont. */
    private function percentile(array $sortedSamples, float $fraction): float
    {
        $position = (count($sortedSamples) - 1) * $fraction;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $sortedSamples[$lower] + ($sortedSamples[$upper] - $sortedSamples[$lower]) * ($position - $lower);
    }
}
