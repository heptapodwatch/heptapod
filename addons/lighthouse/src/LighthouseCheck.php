<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CheckAvailability;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckDetailProvider;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckHistoryProvider;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\LatestRunEvidence;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\ScoresBlock;
use Heptapod\Contracts\ReportsAvailability;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\SecretRedactor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Lighthouse\LighthouseResult;

final readonly class LighthouseCheck implements CheckDetailProvider, CheckHistoryProvider, CheckRunPresenter, CheckType, MailEvidenceProvider, ReportsAvailability
{
    private const array Categories = ['performance' => 'performance', 'accessibility' => 'accessibility', 'best_practices' => 'best-practices', 'seo' => 'seo'];

    private const array Audits = [
        'first_contentful_paint_in_ms' => 'first-contentful-paint',
        'speed_index_in_ms' => 'speed-index',
        'largest_contentful_paint_in_ms' => 'largest-contentful-paint',
        'time_to_interactive_in_ms' => 'interactive',
        'total_blocking_time_in_ms' => 'total-blocking-time',
        'cumulative_layout_shift' => 'cumulative-layout-shift',
    ];

    public function __construct(private ChromiumRenderer $renderer, private LighthousePersistence $store) {}

    public function availability(): CheckAvailability
    {
        return new CheckAvailability(config()->boolean('heptapod.browser.enabled') ? null : 'lighthouse.browser_unavailable');
    }

    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?LighthouseDetail
    {
        if (! $latestRun instanceof LatestRunEvidence || ($latestRun->result !== RunResult::Errored && $latestRun->observationEvidence() === [])) {
            return null;
        }

        return new LighthouseDetail($latestRun->result,
            $latestRun->result === RunResult::Errored ? null : $this->store->metrics($checkId, $latestRun->run),
            $latestRun->result === RunResult::Errored ? null : LighthouseEvidence::from($latestRun->observationEvidence()));
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        if ($run->result === RunResult::Errored && (str_starts_with($run->summary, 'lighthouse.cause.') || $run->summary === 'causes.engine.lighthouse_incomplete')) {
            $details = [];
            $stage = $run->summaryParams['stage'] ?? null;
            if ($stage !== null) {
                if (! is_string($stage) || ! in_array($stage, ['browser_launch', 'navigation', 'gathering', 'auditing', 'reporting', 'unknown'], true)) {
                    throw new \LogicException('Invalid retained Lighthouse failure stage.');
                }
                $details[] = new CheckRunPresentationPartData('lighthouse.stopped_during', labels: ['stage_label' => 'lighthouse.stage.'.$stage]);
            }
            $headlineParams = $run->summaryParams;
            foreach (['browser_launch', 'navigation', 'gathering', 'auditing', 'reporting', 'unknown'] as $reachedStage) {
                $param = 'stage_seconds_'.$reachedStage;
                if (array_key_exists($param, $headlineParams)) {
                    $details[] = new CheckRunPresentationPartData('lighthouse.stage_seconds', ['seconds' => $headlineParams[$param]], labels: ['stage_label' => 'lighthouse.stage.'.$reachedStage]);
                    unset($headlineParams[$param]);
                }
            }
            if (isset($run->summaryParams['message'])) {
                $details[] = new CheckRunPresentationPartData('lighthouse.diagnostic', ['message' => $run->summaryParams['message']], untrusted: ['message' => 'error_message']);
            }

            if (isset($run->summaryParams['last_unrecognised_event'])) {
                $details[] = new CheckRunPresentationPartData('lighthouse.last_unrecognised_event', ['last_unrecognised_event' => $run->summaryParams['last_unrecognised_event']], untrusted: ['last_unrecognised_event' => 'last_unrecognised_event']);
            }

            return new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary, array_diff_key($headlineParams, ['stage' => true, 'message' => true, 'last_unrecognised_event' => true]))], details: $details);
        }
        $observation = $run->observationEvidence();
        if ($run->result === RunResult::Errored || $observation === []) {
            return null;
        }
        $rules = ['issues' => ['present', 'array', 'list'], 'score_drops' => ['present', 'array', 'list']];
        foreach (['issues' => 'notificationThreshold', 'score_drops' => 'previousScore'] as $field => $extra) {
            $rules[$field.'.*.category'] = ['required', Rule::in(array_keys(self::Categories))];
            $rules[$field.'.*.actualScore'] = ['required', 'integer:strict', 'min:0', 'max:100'];
            $rules[$field.'.*.'.$extra] = ['required', 'integer:strict', 'min:0', 'max:100'];
        }
        Validator::make($observation, $rules)->validate();
        $evidence = LighthouseEvidence::from($observation);
        $categories = [];
        foreach ($evidence->issues as $issue) {
            $category = $issue['category'];
            if (isset($categories[$category]) || $issue['actualScore'] >= $issue['notificationThreshold']) {
                throw new \LogicException('Lighthouse threshold facts are invalid.');
            }
            $categories[$category] = ['score' => $issue['actualScore'], 'threshold' => $issue['notificationThreshold']];
        }
        foreach ($evidence->score_drops as $drop) {
            $category = $drop['category'];
            if (isset($categories[$category]['previous']) || $drop['previousScore'] - $drop['actualScore'] < 10
                || (isset($categories[$category]['score']) && $categories[$category]['score'] !== $drop['actualScore'])) {
                throw new \LogicException('Lighthouse score-drop facts are invalid.');
            }
            $categories[$category] = [...($categories[$category] ?? []), 'score' => $drop['actualScore'],
                'previous' => $drop['previousScore'], 'drop' => $drop['previousScore'] - $drop['actualScore']];
        }
        $reasons = [];
        foreach (array_keys(self::Categories) as $position => $category) {
            if (! isset($categories[$category])) {
                continue;
            }
            $params = $categories[$category];
            $key = isset($params['threshold']) ? (isset($params['previous']) ? 'below_and_dropped' : 'below') : 'dropped';
            $reasons[] = ['key' => $position.'.'.$category, 'severity' => RunResult::Warning, 'occurrences' => 1,
                'part' => new CheckRunPresentationPartData('lighthouse.presentation.'.$key, $params, ['category' => 'lighthouse.'.$category])];
        }
        if (! $evidence->load_timed_out) {
            return $reasons === [] ? null : CheckRunPresentationData::forReasons($reasons);
        }
        if ($reasons === []) {
            return new CheckRunPresentationData([new CheckRunPresentationPartData('lighthouse.presentation.load_timed_out')]);
        }
        // The timeout is a caveat on every score, so it stays second: after the first finding, before the headline fold.
        $findings = CheckRunPresentationData::forReasons($reasons)->parts;
        $first = $findings[0];
        $timeout = 'lighthouse.presentation.load_timed_out_continuation';
        $history = new CheckRunPresentationPartData('lighthouse.history.'.($first->key === 'lighthouse.presentation.dropped' ? 'dropped' : 'below').'_and_load_timed_out',
            $first->params, [...$first->labels, 'load_timeout' => $timeout]);

        return new CheckRunPresentationData([$first, new CheckRunPresentationPartData($timeout), ...array_slice($findings, 1)], history_label: $history);
    }

    public function history(int $checkId, bool $canViewCapabilities = false): LighthouseHistoryData
    {
        return $this->store->history($checkId);
    }

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'lighthouse.mail_event.check.failing',
            'check.warning' => 'lighthouse.mail_event.check.warning',
            'check.recovered' => 'lighthouse.mail_event.check.recovered',
            'lighthouse.score_threshold_crossed' => 'lighthouse.mail_event.lighthouse.score_threshold_crossed',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'lighthouse.score_threshold_crossed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        if ($run->result === RunResult::Errored) {
            return [new NoteBlock(__('lighthouse.mail_no_current_scores', locale: $locale), MailTone::CheckError)];
        }
        $observation = $run->observationEvidence();
        if ($run->metrics === null || $observation === []) {
            return [];
        }
        $metrics = LighthouseMetrics::from($run->metrics);
        $evidence = LighthouseEvidence::from($observation);
        $thresholds = array_column($evidence->issues, 'notificationThreshold', 'category');
        $items = [];
        foreach (['performance' => $metrics->performance_score, 'accessibility' => $metrics->accessibility_score, 'best_practices' => $metrics->best_practices_score, 'seo' => $metrics->seo_score] as $category => $score) {
            $items[] = ['label' => __('lighthouse.'.$category, locale: $locale), 'value' => $score, 'min' => $thresholds[$category] ?? null];
        }
        $at = $observedAt->setTimezone($timezone);
        $at->locale($locale);

        return [new ScoresBlock(__('lighthouse.mail_scores', ['at' => $at->isoFormat('ll, HH:mm z')], $locale), $items,
            __('lighthouse.mail_recorded_thresholds', locale: $locale))];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('lighthouse.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        $thresholds = [];
        $metrics = [];
        foreach (self::Categories as $category => $id) {
            $thresholds[$category] = new Field(FieldType::Integer, ['sometimes', 'nullable', 'min:0', 'max:100'], presentation: new FieldPresentation('lighthouse.'.$category));
            $metrics[$category.'_score'] = new Field(FieldType::Integer, ['required', 'min:0', 'max:100']);
        }
        $metrics['progressive_web_app_score'] = new Field(FieldType::Integer, ['present', 'nullable', 'min:0', 'max:100']);
        foreach (self::Audits as $metric => $id) {
            $metrics[$metric] = new Field(FieldType::Number, ['present', 'nullable', 'min:0']);
        }

        return new CheckDefinition(
            CheckKey::Lighthouse, 'checks.lighthouse.label', 'gauge',
            'checks-browser', 170, 86400, 1, ExecutionMode::Poll,
            new Schema([
                'continent' => new Field(FieldType::String, ['sometimes', 'nullable', 'max:100'], presentation: new FieldPresentation('lighthouse.continent', 'lighthouse.continent_help'), default: null),
                'device_emulation' => new Field(FieldType::String, ['sometimes', 'in:mobile,desktop'], presentation: new FieldPresentation('lighthouse.device', options: ['mobile' => 'Mobile', 'desktop' => 'Desktop']), default: 'mobile'),
                'cpu_slowdown_modifier' => new Field(FieldType::Number, ['sometimes', 'nullable', 'min:1', 'max:20'], presentation: new FieldPresentation('lighthouse.cpu'), default: null),
                'notification_settings' => new Field(FieldType::Object, ['sometimes', 'nullable'], properties: $thresholds, presentation: new FieldPresentation('lighthouse.thresholds', 'lighthouse.thresholds_help'), additionalProperties: true, default: null),
                'http_client_headers' => new Field(FieldType::List, ['sometimes', 'max:20'], items: new Field(FieldType::Object, properties: [
                    'name' => new Field(FieldType::String, ['required', 'max:255', 'regex:/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/']),
                    'value' => new Field(FieldType::Secret, ['required', 'max:4096', 'not_regex:/[\r\n]/']),
                ]), presentation: new FieldPresentation('lighthouse.headers', 'lighthouse.headers_help'), default: []),
            ]), new Schema($metrics), emittedEvents: ['lighthouse.score_threshold_crossed'], detailDataClass: LighthouseDetail::class,
        );
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        $config = array_replace($this->definition()->configSchema->defaults(), $context->config);
        $desktop = $config['device_emulation'] === 'desktop';
        /** @var list<array{name: string, value: string}> $checkHeaders */
        $checkHeaders = $config['http_client_headers'] ?? [];
        $headers = [];
        foreach ([...$context->crawlerHeaders, ...$checkHeaders] as $header) {
            // HTTP names are case-insensitive; check-specific headers win over site headers.
            $headers[strtolower($header['name'])] = $header;
        }
        $context->runProgress?->report(0, 3, 'lighthouse');
        try {
            $report = $this->renderer->lighthouse($context->url, $context, [
                'desktop' => $desktop,
                'cpuSlowdownMultiplier' => $config['cpu_slowdown_modifier'] ?? ($desktop ? 1 : 4),
                'extraHeaders' => (object) array_column(array_values($headers), 'value', 'name'),
            ]);
        } catch (LighthouseAuditFailed $exception) {
            $params = [];
            if ($exception->budgetSeconds !== null) {
                $params['seconds'] = $exception->budgetSeconds;
            }
            if ($exception->stage !== null) {
                $params['stage'] = $exception->stage;
            }
            foreach ($exception->stageSeconds as $stage => $seconds) {
                $params['stage_seconds_'.$stage] = $seconds;
            }
            if ($exception->diagnostic !== null) {
                $params['message'] = SecretRedactor::redact($exception->diagnostic,
                    array_column([...$context->crawlerHeaders, ...$checkHeaders], 'value'), [$context->url]);
            }
            if ($exception->lastUnrecognisedEvent !== null) {
                $params['last_unrecognised_event'] = SecretRedactor::redact($exception->lastUnrecognisedEvent,
                    array_column([...$context->crawlerHeaders, ...$checkHeaders], 'value'), [$context->url]);
            }
            $summary = $exception->failureCode === LighthouseFailureCode::AuditIncomplete
                ? 'causes.engine.lighthouse_incomplete' : 'lighthouse.cause.'.$exception->failureCode->value;

            return new CheckOutcome(RunResult::Errored, $summary, $params, null,
                error: new CheckError($exception::class, $exception->getMessage()));
        } catch (LighthouseServiceUnavailable|LighthouseScopeReleaseFailed $exception) {
            $summary = $exception instanceof LighthouseScopeReleaseFailed ? 'lighthouse.cause.scope_release_failed' : 'lighthouse.cause.service_unavailable';

            return new CheckOutcome(RunResult::Errored, $summary, [], null,
                error: new CheckError($exception::class, $exception->getMessage()));
        }
        $context->runProgress?->report(1, 3, 'lighthouse');
        $context->runControl?->assertActive();
        $json = json_encode((object) $report->raw, JSON_THROW_ON_ERROR);
        $archive = json_decode($json, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $raw */
        $raw = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (isset($raw['runtimeError']) || strlen($json) > 8 * 1024 * 1024) {
            throw new \UnexpectedValueException('Lighthouse did not produce a complete bounded report.');
        }
        $rules = [
            'lighthouseVersion' => ['required', 'string'], 'fetchTime' => ['required', 'date'],
            'audits' => ['required', 'array'], 'categories' => ['required', 'array'],
        ];
        foreach (self::Categories as $id) {
            $rules['categories.'.$id.'.score'] = ['required', 'numeric:strict', 'min:0', 'max:1'];
        }
        $rules['categories.pwa.score'] = ['sometimes', 'nullable', 'numeric:strict', 'min:0', 'max:1'];
        foreach (self::Audits as $id) {
            $rules['audits.'.$id.'.numericValue'] = ['sometimes', 'nullable', 'numeric:strict', 'min:0'];
        }
        if (Validator::make($raw, $rules)->fails()) {
            throw new \UnexpectedValueException('Lighthouse report has invalid scores or audit metrics.');
        }
        $parsed = (new LighthouseResult)->setJsonReport($raw);
        $values = [];
        $scores = [];
        foreach (self::Categories as $category => $id) {
            /** @var int|float $score */
            $score = $parsed->get('categories.'.$id.'.score');
            $scores[$category] = (int) round($score * 100);
            $values[$category.'_score'] = $scores[$category];
        }
        /** @var int|float|null $pwa */
        $pwa = $parsed->get('categories.pwa.score');
        $values['progressive_web_app_score'] = $pwa === null ? null : (int) round($pwa * 100);
        foreach (self::Audits as $metric => $id) {
            /** @var int|float|null $value */
            $value = $parsed->get('audits.'.$id.'.numericValue');
            $values[$metric] = $value === null ? null : (float) $value;
        }
        $context->runProgress?->report(2, 3, 'lighthouse');
        $metrics = LighthouseMetrics::from($values);
        $baseline = $this->store->baseline($context->checkId);
        /** @var array<string, int|null> $thresholds */
        $thresholds = $config['notification_settings'] ?? [];
        $issues = [];
        $drops = [];
        $events = [];
        foreach ($scores as $category => $score) {
            $threshold = $thresholds[$category] ?? null;
            $below = $threshold !== null && $score < $threshold;
            if ($below) {
                $issues[] = ['category' => $category, 'actualScore' => $score, 'notificationThreshold' => $threshold];
            }
            if ($baseline === null) {
                continue;
            }
            $previous = $baseline['scores'][$category];
            if ($previous - $score >= 10) {
                $drops[] = ['category' => $category, 'previousScore' => $previous, 'actualScore' => $score];
            }
            // Compare both observations under the current threshold; settings edits alone are not a crossing.
            if ($threshold !== null && ($previous < $threshold) !== $below) {
                $events[] = new AddonEvent('lighthouse.score_threshold_crossed',
                    'lighthouse:'.$context->checkId.':'.$baseline['run_id'].':'.$category.':'.$threshold.':'.($below ? 'below' : 'above'), 1, [
                        'category' => $category, 'direction' => $below ? 'below' : 'above',
                        'previousScore' => $previous, 'actualScore' => $score, 'notificationThreshold' => $threshold,
                    ]);
            }
        }
        /** @var bool $loadTimedOut Validated by the renderer at the service boundary. */
        $loadTimedOut = $raw['load_timed_out'] ?? false;
        $evidence = new LighthouseEvidence($issues, $drops, device_emulation: $desktop ? 'desktop' : 'mobile', load_timed_out: $loadTimedOut);
        /** @var array<string, mixed> $metricValues */
        $metricValues = $metrics->toArray();
        $secrets = [];
        foreach ([...$context->crawlerHeaders, ...$checkHeaders] as $header) {
            if ($header['value'] !== '') {
                $secrets[] = $header['value'];
            }
        }
        if ($archive instanceof \stdClass && ($archive->configSettings ?? null) instanceof \stdClass && ($archive->configSettings->extraHeaders ?? null) instanceof \stdClass) {
            foreach (array_keys(get_object_vars($archive->configSettings->extraHeaders)) as $name) {
                $archive->configSettings->extraHeaders->{$name} = '[redacted]';
            }
        }
        $scrubbed = $this->redactArchive($archive, $secrets);
        $this->store->stage($context, 'report', [[
            'json_report' => json_encode($scrubbed, JSON_THROW_ON_ERROR),
            'metrics' => $metricValues, 'evidence' => $evidence->toArray(),
        ]]);
        $thresholdWarning = $issues !== [] || $drops !== [];
        $warning = $thresholdWarning || $loadTimedOut;
        $summary = $thresholdWarning ? 'lighthouse.warning' : ($loadTimedOut ? 'lighthouse.load_timed_out' : 'lighthouse.succeeded');

        return new CheckOutcome($warning ? RunResult::Warning : RunResult::Succeeded,
            $summary, [], $metrics, $evidence, $events);
    }

    /** @param list<string> $secrets */
    private function redactArchive(mixed $value, array $secrets): mixed
    {
        if ($value instanceof \stdClass) {
            $scrubbed = new \stdClass;
            foreach (get_object_vars($value) as $name => $child) {
                $scrubbed->{SecretRedactor::redact((string) $name, $secrets)} = $this->redactArchive($child, $secrets);
            }

            return $scrubbed;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $child): mixed => $this->redactArchive($child, $secrets), $value);
        }
        if (! is_scalar($value) && $value !== null) {
            throw new \UnexpectedValueException('Lighthouse archive contains a non-JSON value.');
        }
        $text = (string) $value;
        $scrubbed = SecretRedactor::redact($text, $secrets);

        return $scrubbed === $text ? $value : $scrubbed;
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }
}
