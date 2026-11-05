<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckHistoryProvider;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\HealthProbeEvaluator;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Contracts\SuppressionSubject;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use OhDear\HealthCheckResults\CheckResults;

final readonly class ApplicationHealthCheck implements CheckHistoryProvider, CheckRunPresenter, CheckType, HealthProbeEvaluator, MailEvidenceProvider
{
    public function __construct(private ApplicationHealthPersistence $store, private OutboundHttp $http) {}

    public function history(int $checkId, bool $canViewCapabilities = false): ApplicationHealthDetailData
    {
        return $this->store->detail($checkId);
    }

    public function probeComponentCount(string $body, CarbonImmutable $now): ?int
    {
        if (! json_validate($body, 64)) {
            return null;
        }
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_is_list($payload)) {
            return null;
        }
        /** @var array<string, mixed> $payload */
        $validated = $this->validatedPayload($payload);
        if ($validated === null || $validated['finishedAt'] > $now->getTimestamp() + $this->limit('clock_skew_seconds', 0)
            || $validated['finishedAt'] < $now->getTimestamp() - $this->limit('stale_after_seconds', 1)) {
            return null;
        }

        return count(CheckResults::fromJson(json_encode($validated, JSON_THROW_ON_ERROR))->checkResults());
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        $observation = $run->observationEvidence();
        if ($run->result === RunResult::Errored || $observation === []) {
            return null;
        }
        $evidence = ApplicationHealthEvidence::from($observation);
        if ($run->event?->componentId !== null) {
            $component = array_find($evidence->components, static fn (ApplicationHealthComponent $component): bool => $component->id === $run->event->componentId);

            return $component === null ? null : new CheckRunPresentationData([new CheckRunPresentationPartData('application_health.presentation.component', ['name' => $component->name, 'message' => $component->notificationMessage !== '' ? $component->notificationMessage : $component->shortSummary],
                untrusted: ['name' => 'component', 'message' => 'component_message'])]);
        }
        $failed = array_values(array_filter($evidence->components, static fn (ApplicationHealthComponent $component): bool => $component->latest_result === 'failed'));
        if ($failed === []) {
            return null;
        }

        $visible = CheckRunPresentationData::headlineCount(count($failed), 2);
        $remaining = count($failed) - $visible;

        return new CheckRunPresentationData([new CheckRunPresentationPartData($remaining >= 2 ? 'application_health.presentation.failed_more' : 'application_health.presentation.failed', [
            'count' => count($failed), 'total' => count($evidence->components),
            'names' => implode(', ', array_map(static fn (ApplicationHealthComponent $component): string => $component->name, array_slice($failed, 0, $visible))),
            'remaining' => $remaining,
        ], untrusted: ['names' => 'failing_components'])]);
    }

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'application_health.mail_event.check.failing',
            'check.warning' => 'application_health.mail_event.check.warning',
            'check.recovered' => 'application_health.mail_event.check.recovered',
            'application_health.component_changed' => 'application_health.mail_event.application_health.component_changed',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'application_health.component_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $observation = $run->observationEvidence();
        if ($observation === []) {
            $message = $event->payload['notificationMessage'] ?? $event->payload['shortSummary'] ?? null;

            return is_string($message) && $message !== '' ? [new NoteBlock(mb_strcut($message, 0, 1024, 'UTF-8'))] : [];
        }
        $evidence = ApplicationHealthEvidence::from($observation);
        $components = $event->componentId === null
            ? array_values(array_filter($evidence->components, static fn (ApplicationHealthComponent $component): bool => in_array($component->latest_result, ['failed', 'warning'], true)))
            : array_values(array_filter($evidence->components, static fn (ApplicationHealthComponent $component): bool => $component->id === $event->componentId));
        if ($components === []) {
            return [new NoteBlock(__($run->summary, $run->summaryParams, $locale))];
        }
        $rows = [];
        foreach (array_slice($components, 0, 5) as $component) {
            $rows[] = [new Cell($component->label !== '' ? $component->label : $component->name), new Cell(mb_strcut($component->notificationMessage !== '' ? $component->notificationMessage : $component->shortSummary, 0, 1024, 'UTF-8'), tone: $component->latest_result === 'failed' ? MailTone::Failed : ($component->latest_result === 'warning' ? MailTone::Warning : MailTone::Recovered))];
        }
        $healthy = count(array_filter($evidence->components, static fn (ApplicationHealthComponent $component): bool => $component->latest_result === 'succeeded'));

        $more = count($components) > count($rows) ? __('application_health.mail_more', ['count' => count($components) - count($rows)], $locale) : ($event->componentId === null && $healthy > 0 ? __('application_health.mail_healthy', ['count' => $healthy], $locale) : null);
        if ($more !== null && ! is_string($more)) {
            throw new \LogicException('Application health more label must be text.');
        }

        return [new TableBlock(__('application_health.mail_results', locale: $locale), [__('application_health.mail_check', locale: $locale), __('application_health.mail_result', locale: $locale)], $rows, $more)];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('application_health.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        return new CheckDefinition(
            CheckKey::ApplicationHealth, 'checks.application_health.label', 'heart-pulse',
            'checks-fast', 30, 60, 1, ExecutionMode::Poll,
            new Schema([
                'result_url' => new Field(FieldType::String, ['required', 'url:http,https', 'max:2048'], presentation: new FieldPresentation('application_health.result_url'), targetUrl: true),
                'secret' => new Field(FieldType::Secret, ['required', 'max:10000', 'not_regex:/[\r\n]/'], presentation: new FieldPresentation('application_health.secret')),
                'headers' => new Field(FieldType::List, ['sometimes', 'max:100'], items: new Field(FieldType::Object, properties: [
                    'name' => new Field(FieldType::String, ['required', 'max:255', 'regex:/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', 'not_regex:/^oh-dear-health-check-secret$/i']),
                    'value' => new Field(FieldType::Secret, ['present', 'max:10000', 'not_regex:/[\r\n]/']),
                ]), default: []),
            ]),
            new Schema([
                'succeeded' => new Field(FieldType::Integer, ['required', 'min:0']),
                'warning' => new Field(FieldType::Integer, ['required', 'min:0']),
                'failed' => new Field(FieldType::Integer, ['required', 'min:0']),
                'finished_at' => new Field(FieldType::Integer, ['present', 'nullable', 'min:1']),
                'response_time_in_seconds' => new Field(FieldType::Number, ['present', 'nullable', 'min:0']),
            ]), emittedEvents: ['application_health.component_changed'], recommended: false,
        );
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        /** @var array{result_url: string, secret: string, headers: list<array{name: string, value: string}>} $settings */
        $settings = array_replace($this->definition()->configSchema->defaults(), $context->config);
        $staleAfter = $this->limit('stale_after_seconds', 1);
        $clockSkew = $this->limit('clock_skew_seconds', 0);
        $this->limit('max_components', 1, 1000);
        $maxBytes = $this->limit('max_response_bytes', 1);
        $headers = ['oh-dear-health-check-secret' => [$settings['secret']]];
        $secrets = [$settings['secret']];
        foreach ($settings['headers'] as $header) {
            $headers[strtolower($header['name'])][] = $header['value'];
            $secrets[] = $header['value'];
        }
        // The health endpoint returns JSON; the shared request profile is HTML-first. A configured Accept header wins.
        $headers['accept'] ??= ['application/json'];
        foreach ($context->crawlerHeaders as $header) {
            $secrets[] = $header['value'];
        }
        $secrets = array_values(array_filter($secrets, static fn (string $value): bool => $value !== ''));
        $response = $this->http->fetch(new FetchRequest(
            $settings['result_url'], headers: $headers, maxWireBytes: $maxBytes, maxDecodedBytes: $maxBytes,
            redirects: RedirectPolicy::Off, timeoutSeconds: 30, acceptedStatuses: range(200, 399),
        ), $context);
        $time = isset($response->transfers[0]['total_time']) ? (float) $response->transfers[0]['total_time'] : null;
        if ($response->failure instanceof FetchFailure) {
            $reason = match ($response->failure->kind) {
                FetchFailureKind::TooLarge => 'response_too_large',
                FetchFailureKind::HttpStatus => 'http_status',
                FetchFailureKind::Timeout, FetchFailureKind::Tls, FetchFailureKind::Dns, FetchFailureKind::Connect,
                FetchFailureKind::RedirectBlocked, FetchFailureKind::TooManyRedirects, FetchFailureKind::PrivateTarget,
                FetchFailureKind::InvalidRequest => 'unreachable',
            };

            return $this->failure($reason, $time, $response->status, failureKind: $response->failure->kind->value);
        }
        if ($response->status >= 300 && $response->status < 400) {
            $host = parse_url(array_change_key_case($response->headers)['location'][0] ?? '', PHP_URL_HOST);
            $host = is_string($host) ? $host : parse_url($settings['result_url'], PHP_URL_HOST);

            return $this->failure('redirect', $time, $response->status, redirectHost: is_string($host) ? $this->safeText($host, $secrets) : null);
        }
        $body = $response->body;
        if (! json_validate($body, 64)) {
            return $this->failure('invalid_json', $time, $response->status);
        }
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_is_list($payload)) {
            return $this->failure('invalid_payload', $time, $response->status);
        }
        /** @var array<string, mixed> $payload */
        $validated = $this->validatedPayload($payload);
        if ($validated === null) {
            return $this->failure('invalid_payload', $time, $response->status);
        }
        $finishedAt = $validated['finishedAt'];
        if ($finishedAt > $context->startedAt->getTimestamp() + $clockSkew) {
            return $this->failure('future_finished_at', $time, $response->status, $finishedAt);
        }
        if ($finishedAt < $context->startedAt->getTimestamp() - $staleAfter) {
            return $this->failure('stale', $time, $response->status, $finishedAt);
        }
        $reported = CheckResults::fromJson(json_encode($validated, JSON_THROW_ON_ERROR))->checkResults();
        $baselines = $this->store->components($context->checkId, array_column($validated['checkResults'], 'name'));
        $counts = ['succeeded' => 0, 'warning' => 0, 'failed' => 0];
        $skipped = 0;
        $retained = [];
        $components = $rows = $events = [];
        foreach ($reported as $source) {
            $baseline = $baselines[hash('sha256', $source->name)];
            // Only an explicit ok is healthy. Skipped is shown as unverified and never counts as healthy.
            $result = match ($source->status) {
                'ok' => CheckResult::Succeeded,
                'failed', 'crashed' => CheckResult::Failed,
                'warning' => CheckResult::Warning,
                'skipped' => CheckResult::Pending,
                default => null,
            };
            if ($result === null) {
                return new CheckOutcome(RunResult::Errored, 'application_health.unknown_status',
                    ['status' => $this->safeText($source->status, $secrets), 'component' => $this->safeText($source->label !== '' ? $source->label : $source->name, $secrets)],
                    new ApplicationHealthMetrics(0, 0, 0, $finishedAt, $time), new ApplicationHealthEvidence('unknown_status', $response->status, null, []),
                    error: new CheckError(\UnexpectedValueException::class, 'Application health reported an unknown component status.'));
            }
            // The last verified non-ok severity survives skipped runs; Check-error runs never replace the stored baseline.
            if ($result === CheckResult::Pending) {
                $skipped++;
                $previous = $baseline['snapshot']['latest_result'] ?? null;
                $unresolved = in_array($previous, ['failed', 'warning'], true) ? $previous : ($baseline['snapshot']['unresolved_result'] ?? null);
                if ($unresolved !== null) {
                    $retained[] = $unresolved;
                }
            } else {
                $counts[$result->value]++;
                $unresolved = $result === CheckResult::Succeeded ? null : $result->value;
            }
            $identity = hash('sha256', json_encode([$source->status, $source->notificationMessage, $source->shortSummary], JSON_THROW_ON_ERROR));
            /** @var array<int|string, mixed> $meta */
            $meta = $this->safeMeta($source->meta, $secrets);
            $component = new ApplicationHealthComponent($baseline['id'], $this->safeText($source->name, $secrets),
                $this->safeText($source->label, $secrets), $this->safeText($source->status, $secrets),
                $this->safeText($source->notificationMessage, $secrets), $this->safeText($source->shortSummary, $secrets), $meta, $result->value);
            $components[] = $component;
            $rows[] = ['snapshot' => [...$component->toArray(), 'unresolved_result' => $unresolved], 'change_identity' => $identity];
            // A component that turned skipped is unverified; it reports no new state to alert on.
            if ($baseline['change_identity'] !== null && $baseline['change_identity'] !== $identity && $result !== CheckResult::Pending) {
                $payload = [
                    'component_id' => $component->id, 'name' => $component->name,
                    'old_status' => $this->safeText($baseline['snapshot']['status'] ?? '', $secrets),
                    'status' => $component->status, 'change_identity' => $identity,
                    'result' => $result->value, 'from_result' => $baseline['snapshot']['latest_result'] ?? null,
                ];
                foreach (['notificationMessage' => $source->notificationMessage, 'shortSummary' => $source->shortSummary] as $key => $message) {
                    if (! $this->containsSecret($message, $secrets)) {
                        $payload[$key] = mb_substr($message, 0, 255);
                    }
                }
                $events[] = new AddonEvent('application_health.component_changed',
                    'application_health:'.$component->id.':'.$baseline['run_id'].':'.$identity, 1, $payload,
                    new SuppressionSubject($context->checkId, SuppressionComponent::ApplicationHealth, $component->id));
            }
        }
        $this->store->stage($context, 'components', [['components' => $rows]]);
        $result = $counts['failed'] > 0 ? RunResult::Failed : ($counts['warning'] > 0 ? RunResult::Warning : RunResult::Succeeded);

        [$summary, $summaryParams] = match (true) {
            $components === [] => ['application_health.no_checks', []],
            $skipped > 0 => ['application_health.summary_skipped', [...$counts, 'skipped' => $skipped]],
            default => ['application_health.summary', $counts],
        };

        // Escalation: verified warnings and failures report normally. Recovery: a skipped component keeps its last unresolved
        // severity, so a run that would otherwise recover is incomplete and cannot resolve or reach Succeeded. A success beside
        // a retained severity verifies nothing about that severity, so it cannot end even a pure Check-error incident.
        $recoveryUnproven = ($skipped > 0 && array_sum($counts) === 0) || ($retained !== [] && $result !== RunResult::Failed);
        $verifiedNothing = $recoveryUnproven && $result === RunResult::Succeeded;

        return new CheckOutcome($result, $summary, $summaryParams,
            new ApplicationHealthMetrics(...[...array_values($counts), $finishedAt, $time]),
            new ApplicationHealthEvidence(null, $response->status, null, $components), $events,
            incomplete: $recoveryUnproven, confirmedIncompleteFailure: $recoveryUnproven && ! $verifiedNothing && $result === RunResult::Warning,
            verifiedNothing: $verifiedNothing);
    }

    /** @param array<string, mixed> $payload
     * @return array{finishedAt: int, checkResults: array<int|string, array{name: string}>}|null
     */
    private function validatedPayload(array $payload): ?array
    {
        $validator = Validator::make($payload, [
            'finishedAt' => ['required', 'integer:strict', 'min:1'],
            'checkResults' => ['present', 'array', 'max:'.$this->limit('max_components', 1, 1000)],
            'checkResults.*' => ['required', 'array:name,label,status,notificationMessage,shortSummary,meta'],
            'checkResults.*.name' => ['required', 'string', 'max:255', 'distinct:strict'],
            'checkResults.*.label' => ['sometimes', 'string', 'max:255'],
            'checkResults.*.status' => ['required', 'string', 'max:255'],
            'checkResults.*.notificationMessage' => ['sometimes', 'string', 'max:10000'],
            'checkResults.*.shortSummary' => ['sometimes', 'string', 'max:10000'],
            'checkResults.*.meta' => ['sometimes', 'array'],
        ]);
        if ($validator->fails()) {
            return null;
        }
        /** @var array{finishedAt: int, checkResults: array<int|string, array{name: string}>} $validated */
        $validated = $validator->validated();

        return $validated;
    }

    private function limit(string $key, int $min, int $max = PHP_INT_MAX): int
    {
        $value = Config::get('application_health.'.$key);
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException('Invalid application health limit: '.$key);
        }

        return $value;
    }

    private function failure(string $reason, ?float $time, ?int $status = null, ?int $finishedAt = null, ?string $redirectHost = null, ?string $failureKind = null): CheckOutcome
    {
        // Without a current, valid result no component health is known: a Check error, not a reported failure.
        return new CheckOutcome(RunResult::Errored, 'application_health.'.$reason, [],
            new ApplicationHealthMetrics(0, 0, 0, $finishedAt, $time), new ApplicationHealthEvidence($reason, $status, $redirectHost, [], $failureKind),
            error: new CheckError(ReclassifiedCheckError::class, 'Application health result unavailable: '.$reason));
    }

    /** @param list<string> $secrets */
    private function containsSecret(string $text, array $secrets): bool
    {
        return array_any($secrets, fn (string $secret): bool => stripos($text, $secret) !== false);
    }

    /** @param list<string> $secrets */
    private function safeText(string $text, array $secrets): string
    {
        return $this->containsSecret($text, $secrets) ? '[redacted]' : mb_substr($text, 0, 255);
    }

    /** @param array<int|string, mixed> $meta
     * @param  list<string>  $secrets
     * @return array<int|string, mixed>
     */
    private function safeMeta(array $meta, array $secrets): array
    {
        $safe = [];
        foreach ($meta as $key => $value) {
            $key = $this->containsSecret((string) $key, $secrets) ? '[redacted]' : $key;
            $safe[$key] = is_array($value) ? $this->safeMeta($value, $secrets) : (is_scalar($value) && $this->containsSecret((string) $value, $secrets) ? '[redacted]' : $value);
        }

        return $safe;
    }
}
