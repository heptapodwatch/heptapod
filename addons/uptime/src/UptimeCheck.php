<?php

declare(strict_types=1);

namespace Heptapod\Addons\Uptime;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckHistoryProvider;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckSummaryPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\AutomatedCheckBlock;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\SummaryParameters;
use Heptapod\Contracts\TlsFailureReason;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

final readonly class UptimeCheck implements CheckHistoryProvider, CheckRunPresenter, CheckSummaryPresenter, CheckType, MailEvidenceProvider
{
    public function __construct(private OutboundHttp $http, private ?CertificateFetcher $certificates = null) {}

    public function definition(): CheckDefinition
    {
        $pair = new Field(FieldType::Object, properties: [
            'name' => new Field(FieldType::String, ['required']),
            'value' => new Field(FieldType::String, ['present']),
        ]);
        $headers = new Field(FieldType::Object, properties: [
            'name' => new Field(FieldType::String, ['required', 'regex:/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/']),
            'value' => new Field(FieldType::Secret, ['present', 'not_regex:/[\r\n]/']),
        ]);
        $number = new Field(FieldType::Number, ['present', 'nullable', 'min:0']);
        $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time'], $number);
        foreach (['header_size', 'size_download', 'speed_download'] as $key) {
            $curl[$key] = new Field(FieldType::Integer, ['present', 'nullable', 'min:0']);
        }

        return new CheckDefinition(
            CheckKey::Uptime, 'checks.uptime.label', 'activity', 'checks-fast', 60, 60, 2, ExecutionMode::Poll,
            new Schema([
                'location' => new Field(FieldType::String, ['sometimes'], presentation: new FieldPresentation('uptime.location', 'uptime.local_probe')),
                'http_verb' => new Field(FieldType::String, ['sometimes', 'in:GET,HEAD,POST,PUT,PATCH,DELETE,OPTIONS'], default: 'GET', uppercase: true),
                'look_for_string' => new Field(FieldType::String, ['sometimes', 'nullable'], default: null),
                'absent_string' => new Field(FieldType::String, ['sometimes', 'nullable'], default: null),
                'expected_response_headers' => new Field(FieldType::List, ['sometimes'], items: new Field(FieldType::Object, properties: [
                    'name' => new Field(FieldType::String, ['required']),
                    'condition' => new Field(FieldType::String, ['required', 'in:equals,not equals,contains,not contains']),
                    'value' => new Field(FieldType::String, ['present']),
                ]), default: []),
                'response_body_assertions' => new Field(FieldType::List, ['sometimes', 'nullable', 'max:10'], items: new Field(FieldType::Object, properties: [
                    'path' => new Field(FieldType::String, ['required']),
                    'operator' => new Field(FieldType::String, ['required', 'in:equals,not equals,contains,greater than,less than,exists,does not exist']),
                    'value' => new Field(FieldType::String, ['present_unless:values.response_body_assertions.*.operator,exists,does not exist', 'nullable']),
                ]), default: []),
                'payload' => new Field(FieldType::List, ['sometimes'], items: $pair, default: []),
                'raw_payload' => new Field(FieldType::String, ['sometimes', 'nullable', 'json'], default: null),
                'timeout' => new Field(FieldType::Integer, ['sometimes', 'min:1'], default: 30),
                'valid_status_codes' => new Field(FieldType::List, ['sometimes', 'min:1'], items: new Field(FieldType::String, ['required', 'regex:/^[1-5][0-9*]{0,2}$/']), default: ['2*']),
                'max_redirect_count' => new Field(FieldType::Integer, ['sometimes', 'min:0'], default: 5),
                'expected_final_redirect_url' => new Field(FieldType::String, ['sometimes', 'nullable', 'url:http,https'], default: null),
                'http_client_headers' => new Field(FieldType::List, ['sometimes'], items: $headers, default: []),
            ]),
            new Schema([
                'dns_time_in_seconds' => $number,
                'tcp_time_in_seconds' => $number,
                'ssl_handshake_time_in_seconds' => $number,
                'remote_server_processing_time_in_seconds' => $number,
                'download_time_in_seconds' => $number,
                'total_time_in_seconds' => $number,
                'curl' => new Field(FieldType::Object, ['required'], properties: $curl),
                'date' => new Field(FieldType::String, ['required', 'date']),
            ]),
        );
    }

    public function history(int $checkId, bool $canViewCapabilities = false): UptimeHistoryData
    {
        $query = DB::table('check_runs')->where('check_id', $checkId);
        $runs = (clone $query)->orderByDesc('ended_at')->orderByDesc('id')->limit(20)->get();
        $failures = (clone $query)->where('ended_at', '>=', now()->subDays(90))
            ->whereIn('result', [RunResult::Failed->value, RunResult::Errored->value])
            ->orderByDesc('ended_at')->orderByDesc('id')->limit(50)->get();
        $row = static function (\stdClass $run): array {
            /** @var \stdClass&object{id: int, ended_at: string, result: string, metrics: string|null, evidence: string} $run */
            /** @var array<string, mixed> $metrics */
            $metrics = $run->metrics === null ? [] : json_decode($run->metrics, true, flags: JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $evidence */
            $evidence = json_decode($run->evidence, true, flags: JSON_THROW_ON_ERROR);

            $status = is_int($evidence['status_code'] ?? null) ? $evidence['status_code'] : null;
            $description = $status === null ? null : UptimeSummary::present('causes.http', ['status' => $status]);
            $statusText = $description instanceof CheckRunPresentationPartData ? __($description->key, $description->params) : null;
            if ($statusText !== null && ! is_string($statusText)) {
                throw new \LogicException('HTTP description translation must be text.');
            }

            return [
                'run_id' => $run->id,
                'ended_at' => CarbonImmutable::parse($run->ended_at)->utc()->toIso8601String(),
                'result' => $run->result,
                'status_code' => $status,
                'status_text' => $statusText,
                'reasons' => self::reasons($evidence),
                'total_time_ms' => is_numeric($metrics['total_time_in_seconds'] ?? null) ? (float) $metrics['total_time_in_seconds'] * 1000 : null,
            ];
        };

        $recent = [];
        foreach ($runs as $run) {
            $recent[] = $row($run);
        }
        $recentFailures = [];
        foreach ($failures as $run) {
            $recentFailures[] = $row($run);
        }

        return new UptimeHistoryData($recent, $recentFailures);
    }

    /**
     * Catalog keys naming why a retained attempt did not pass; the run result alone says whether it was Down or a check error.
     * Unknown retained issue strings are not catalog text and are never shown.
     *
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    private static function reasons(array $evidence): array
    {
        $kind = FetchFailureKind::tryFrom(is_string($evidence['failure_kind'] ?? null) ? $evidence['failure_kind'] : '');
        $reasons = [];
        foreach (is_array($evidence['issues'] ?? null) ? $evidence['issues'] : [] as $issue) {
            $reason = match ($issue) {
                'status' => 'uptime.status',
                'required_string' => 'uptime.reason.required_string',
                'absent_string' => 'uptime.reason.absent_string',
                'header' => 'causes.header',
                'redirect_url' => 'causes.redirect_unexpected',
                'body_assertion' => 'causes.body_assertion',
                'body_not_json' => 'uptime.body_not_json',
                'assertion_body_too_large' => 'uptime.assertion_body_too_large',
                'bot_protection' => 'uptime.bot_protection',
                'response_too_large' => 'causes.outbound.too_large',
                'redirect_limit' => 'uptime.redirect_limit',
                'redirect_blocked' => 'uptime.redirect_blocked',
                'private_target' => 'causes.outbound.private_target',
                'connection' => match ($kind) {
                    FetchFailureKind::Timeout, FetchFailureKind::Tls, FetchFailureKind::Dns, FetchFailureKind::Connect,
                    FetchFailureKind::InvalidRequest => 'causes.outbound.'.$kind->value,
                    default => 'causes.connection',
                },
                default => null,
            };
            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    public function presentSummary(string $summary, array $params): CheckRunPresentationPartData
    {
        return UptimeSummary::present($summary, $params);
    }

    public function presentRun(CheckRunPresentationInput $run): CheckRunPresentationData
    {
        return new CheckRunPresentationData([$this->presentSummary($run->summary, $run->summaryParams)]);
    }

    public function mailEventPhrases(): array
    {
        return [
            'site.down' => 'uptime.mail_event.site.down',
            'site.recovered' => 'uptime.mail_event.site.recovered',
            'check.failing' => 'uptime.mail_event.check.failing',
            'check.warning' => 'uptime.mail_event.check.warning',
            'check.recovered' => 'uptime.mail_event.check.recovered',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Down, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Down, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Down, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'incident.still_down' => ['failed' => MailTone::Down],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $reason = $this->presentSummary($run->summary, $run->summaryParams);
        $result = __($reason->key, SummaryParameters::localizedMeasurements($reason->params, $locale), $locale);
        $seconds = $run->metrics['total_time_in_seconds'] ?? null;
        $response = is_numeric($seconds) ? round((float) $seconds * 1000).' ms' : __('uptime.mail_no_response', locale: $locale);

        // Opening snapshots retain the accepted attempt, not the preceding retries.
        return [new TableBlock(__('uptime.mail_last_attempt', locale: $locale),
            [__('uptime.mail_time', locale: $locale), __('uptime.mail_result', locale: $locale), __('uptime.mail_response', locale: $locale)],
            [[new Cell($observedAt->setTimezone($timezone)->format('H:i:s T'), mono: true), new Cell($result, mono: true, tone: MailTone::Down), new Cell($response, mono: true)]])];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $before = $this->presentSummary($opening->summary, $opening->summaryParams);
        $beforeText = __($before->key, SummaryParameters::localizedMeasurements($before->params, $locale), $locale);
        $afterText = __('uptime.mail_recovery_confirmed', locale: $locale);
        if ($recovery instanceof CheckRunPresentationInput) {
            $status = $recovery->evidence['status_code'] ?? null;
            if ($status !== null && ! is_int($status)) {
                throw new \LogicException('Recovery status must be an integer.');
            }
            if ($status !== null) {
                $after = $this->presentSummary('causes.http', ['status' => $status]);
                $afterText = __($after->key, $after->params, $locale);
            }
            $seconds = $recovery->metrics['total_time_in_seconds'] ?? null;
            if ($seconds !== null && ! is_int($seconds) && ! is_float($seconds)) {
                throw new \LogicException('Recovery response time must be numeric.');
            }
            if ($seconds !== null) {
                $afterText .= ' · '.round($seconds * 1000).' ms';
            }
        }

        return [new FactsBlock(__('uptime.mail_what_changed', locale: $locale), [
            ['label' => __('uptime.mail_at_opening', locale: $locale), 'value' => new Cell($beforeText.' · '.$openedAt->setTimezone($timezone)->format('H:i T'), mono: true)],
            ['label' => __('uptime.mail_at_recovery', locale: $locale), 'value' => new Cell($afterText.' · '.$recoveredAt->setTimezone($timezone)->format('H:i T'), mono: true, tone: MailTone::Recovered)],
        ])];
    }

    public function persistence(): ?RunPersistence
    {
        return null;
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        /** @var array{location?: string, http_verb: string, look_for_string: ?string, absent_string: ?string, expected_response_headers: list<array{name: string, condition: string, value: string}>, response_body_assertions: ?list<array{path: string, operator: string, value?: ?string}>, payload: list<array{name: string, value: string}>, raw_payload: ?string, timeout: int, valid_status_codes: list<string>, max_redirect_count: int, expected_final_redirect_url: ?string, http_client_headers: list<array{name: string, value: string}>} $config Validated at the configuration boundary. */
        $config = array_replace($this->definition()->configSchema->defaults(), $context->config);
        $headers = [];
        foreach ($config['http_client_headers'] as $header) {
            $headers[strtolower($header['name'])][] = $header['value'];
        }
        $body = null;
        if ($config['raw_payload'] !== null) {
            $body = $config['raw_payload'];
            $headers['content-type'] = ['application/json'];
        } elseif ($config['payload'] !== []) {
            // Keep ordered duplicate names rather than collapsing pairs into an associative map.
            $pairs = [];
            foreach ($config['payload'] as $pair) {
                $pairs[] = urlencode($pair['name']).'='.urlencode($pair['value']);
            }
            $body = implode('&', $pairs);
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $maxResponseBytes = Config::integer('heptapod.uptime.max_response_bytes');
        $response = $this->http->fetch(new FetchRequest(
            $context->url, $config['http_verb'], $headers, $body,
            maxWireBytes: $maxResponseBytes, maxDecodedBytes: $maxResponseBytes,
            redirects: RedirectPolicy::Operator, maxRedirects: $config['max_redirect_count'],
            timeoutSeconds: (float) $config['timeout'], acceptedStatuses: range(100, 599), strictRedirects: false,
        ), $context);
        $transfers = $response->transfers;
        if ($response->failure instanceof FetchFailure) {
            $issue = match ($response->failure->kind) {
                FetchFailureKind::TooManyRedirects => 'redirect_limit',
                FetchFailureKind::RedirectBlocked => 'redirect_blocked',
                FetchFailureKind::PrivateTarget => 'private_target',
                FetchFailureKind::TooLarge => 'response_too_large',
                FetchFailureKind::HttpStatus => 'status',
                FetchFailureKind::Timeout, FetchFailureKind::Tls, FetchFailureKind::Dns, FetchFailureKind::Connect,
                FetchFailureKind::InvalidRequest => 'connection',
            };

            return $this->outcome($context, $transfers, $response->status, [$issue], $response->failure->kind, $response->effectiveUrl, $response->failure->tlsReason, $response->failure->tlsTarget);
        }

        $issues = [];
        if (! Str::is($config['valid_status_codes'], (string) $response->status)) {
            // A probe refusal says nothing about whether visitors can reach the site.
            if (AutomatedCheckBlock::reason($response->status, $response->headers, substr($response->body, 0, AutomatedCheckBlock::BODY_PREFIX_BYTES)) === AutomatedCheckBlockReason::BotProtection) {
                return $this->outcome($context, $transfers, $response->status, ['bot_protection'], null, $response->effectiveUrl ?? $context->url);
            }
            $issues[] = 'status';
        }
        if ($config['look_for_string'] !== null && ! str_contains($response->body, $config['look_for_string'])) {
            $issues[] = 'required_string';
        }
        if ($config['absent_string'] !== null && str_contains($response->body, $config['absent_string'])) {
            $issues[] = 'absent_string';
        }
        $responseHeaders = array_change_key_case($response->headers, CASE_LOWER);
        foreach ($config['expected_response_headers'] as $header) {
            $values = $responseHeaders[strtolower($header['name'])] ?? null;
            if ($values === null || ! $this->compare(implode(', ', $values), $header['condition'], $header['value'])) {
                $issues[] = 'header';
                break;
            }
        }
        if ($config['expected_final_redirect_url'] !== null && ($response->effectiveUrl ?? $context->url) !== $config['expected_final_redirect_url']) {
            $issues[] = 'redirect_url';
        }
        $assertionIssue = $this->bodyAssertionIssue($response->body, $config['response_body_assertions'] ?? []);
        if ($assertionIssue !== null) {
            $issues[] = $assertionIssue;
        }

        return $this->outcome($context, $transfers, $response->status, $issues, null, $response->effectiveUrl ?? $context->url);
    }

    /**
     * Oh Dear only evaluates assertions for valid JSON bodies smaller than 2 MB; an unevaluated assertion is never a pass.
     *
     * @param  list<array{path: string, operator: string, value?: ?string}>  $assertions
     * @return 'body_assertion'|'body_not_json'|'assertion_body_too_large'|null
     */
    private function bodyAssertionIssue(string $body, array $assertions): ?string
    {
        if ($assertions === []) {
            return null;
        }
        if (strlen($body) >= 2 * 1024 * 1024) {
            return 'assertion_body_too_large';
        }
        if (! json_validate($body)) {
            return 'body_not_json';
        }
        $body = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $missing = new \stdClass;
        foreach ($assertions as $assertion) {
            $values = [$body];
            foreach (explode('.', $assertion['path']) as $segment) {
                $next = [];
                foreach ($values as $value) {
                    if ($segment === '*') {
                        array_push($next, ...(is_array($value) && $value !== [] ? array_values($value) : [$missing]));
                    } else {
                        $next[] = is_array($value) && array_key_exists($segment, $value) ? $value[$segment] : $missing;
                    }
                }
                $values = $next;
            }
            foreach ($values as $actual) {
                $pass = match ($assertion['operator']) {
                    'exists' => $actual !== $missing,
                    'does not exist' => $actual === $missing,
                    default => $actual !== $missing && $this->compare($actual, $assertion['operator'], $assertion['value'] ?? null),
                };
                if (! $pass) {
                    return 'body_assertion';
                }
            }
        }

        return null;
    }

    private function compare(mixed $actual, string $operator, ?string $expected): bool
    {
        $text = is_string($actual) ? $actual : json_encode($actual, JSON_THROW_ON_ERROR);

        return match ($operator) {
            'equals' => $text === ($expected ?? 'null'),
            'not equals' => $text !== ($expected ?? 'null'),
            'contains' => is_array($actual) ? in_array($expected, $actual, true) : str_contains($text, $expected ?? ''),
            'not contains' => ! str_contains($text, $expected ?? ''),
            'greater than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            default => throw new \LogicException('Unvalidated response assertion operator.'),
        };
    }

    /** @param list<array<string, mixed>> $transfers
     * @param  list<string>  $issues
     */
    private function outcome(CheckContext $context, array $transfers, ?int $status, array $issues, ?FetchFailureKind $failure = null, ?string $effectiveUrl = null, ?TlsFailureReason $tlsReason = null, ?string $tlsTarget = null): CheckOutcome
    {
        $curl = [];
        $last = $transfers === [] ? [] : array_last($transfers);
        foreach (['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'] as $key) {
            $value = $last[$key] ?? null;
            $curl[$key] = is_int($value) || is_float($value) ? (in_array($key, ['header_size', 'size_download', 'speed_download'], true) ? (int) $value : $value) : null;
        }
        $metrics = new UptimeMetrics(
            $this->phase($transfers, 'namelookup_time'),
            $this->phase($transfers, 'connect_time', 'namelookup_time'),
            $this->phase($transfers, 'appconnect_time', 'connect_time', true),
            $this->phase($transfers, 'starttransfer_time', 'pretransfer_time'),
            $this->phase($transfers, 'total_time', 'starttransfer_time'),
            $this->phase($transfers, 'total_time'),
            $curl,
            $context->startedAt->toIso8601String(),
        );

        $summary = match ($issues) {
            [] => 'uptime.succeeded',
            ['response_too_large'] => 'uptime.response_too_large',
            ['assertion_body_too_large'] => 'uptime.assertion_body_too_large',
            ['bot_protection'] => 'uptime.bot_protection',
            ['redirect_blocked'] => 'checks.engine.redirect_blocked',
            ['private_target'] => 'checks.engine.private_target',
            ['redirect_limit'] => 'uptime.redirect_limit',
            ['status'] => 'uptime.status',
            default => 'uptime.failed',
        };
        $summaryParams = [];
        if ($issues === ['response_too_large']) {
            $limit = Number::format(Config::integer('heptapod.uptime.max_response_bytes') / 1048576, maxPrecision: 9, locale: Lang::locale());
            if ($limit === false) {
                throw new \LogicException('Unable to format uptime response limit.');
            }
            $summaryParams = ['limit' => $limit.' MiB'];
        }

        /** @var array{timeout: int, expected_final_redirect_url: ?string, look_for_string: ?string, absent_string: ?string} $config */
        $config = array_replace($this->definition()->configSchema->defaults(), $context->config);
        if ($failure === FetchFailureKind::Timeout) {
            $summary = 'causes.timeout';
            $summaryParams = ['seconds' => $config['timeout']];
        } elseif ($failure === FetchFailureKind::Tls) {
            $diagnosticUrl = $tlsTarget ?? $context->url;
            $host = (string) parse_url($diagnosticUrl, PHP_URL_HOST);
            $tls = ($tlsReason ?? TlsFailureReason::Handshake)->summary($host);
            // Optional diagnostics must fit their fixed 10-second cap and never change the native TLS observation.
            if (! $this->certificates instanceof CertificateFetcher) {
                Log::notice('Certificate diagnostics unavailable; native TLS reason retained.', [
                    'check_id' => $context->checkId, 'run_id' => $context->runId,
                    'diagnostics_available' => false,
                    'native_reason' => ($tlsReason ?? TlsFailureReason::Handshake)->value,
                    'summary' => $tls['key'],
                ]);
            } elseif ($context->deadline->getTimestampMs() - CarbonImmutable::now()->getTimestampMs() >= 10_000) {
                try {
                    $tls = $this->certificates->fetch($host, (int) (parse_url($diagnosticUrl, PHP_URL_PORT) ?? 443), (string) parse_url($context->url, PHP_URL_HOST))->failureSummary($tlsReason);
                } catch (FetchException $exception) {
                    // Optional diagnostics may be refused; the primary native TLS observation remains authoritative.
                    Log::notice('Certificate diagnostics refused; native TLS reason retained.', [
                        'check_id' => $context->checkId, 'run_id' => $context->runId,
                        'diagnostic_failure' => $exception->failure->kind->value,
                    ]);
                } catch (ExternalNetworkException|ExternalTimeoutException) {
                    // Capture adds certificate facts; its absence must not erase the already known native reason.
                }
            }
            $summary = $tls['key'];
            $summaryParams = $tls['params'];
        } elseif (in_array('status', $issues, true) && $status !== null) {
            $summary = 'causes.http';
            $summaryParams = ['status' => $status];
        } elseif (in_array('required_string', $issues, true)) {
            $summary = 'causes.keyword_missing';
            $summaryParams = ['keyword' => $config['look_for_string'] ?? ''];
        } elseif (in_array('absent_string', $issues, true)) {
            $summary = 'causes.keyword_present';
            $summaryParams = ['keyword' => $config['absent_string'] ?? ''];
        } elseif (in_array('redirect_url', $issues, true) && $effectiveUrl !== null) {
            $summary = 'causes.redirect';
            $summaryParams = ['actual' => $effectiveUrl, 'expected' => $config['expected_final_redirect_url'] ?? ''];
        } elseif ($issues !== [] && $summary === 'uptime.failed') {
            $summary = match ($issues[0]) {
                'connection' => 'causes.connection',
                'header' => 'causes.header',
                'body_assertion' => 'causes.body_assertion',
                'body_not_json' => 'uptime.body_not_json',
                'redirect_url' => 'causes.redirect_unexpected',
                default => $summary,
            };
        }

        $presented = $this->presentSummary($summary, $summaryParams);
        // Local inspection limits and probe refusals mean we could not check; any confirmed failure still wins.
        $checkError = $issues !== [] && array_diff($issues, ['response_too_large', 'assertion_body_too_large', 'bot_protection']) === []
            ? new CheckError(ReclassifiedCheckError::class, match ($issues[0]) {
                'bot_protection' => 'Monitored URL served a bot protection challenge.',
                default => 'Response exceeded the uptime inspection limit.',
            })
            : null;

        return new CheckOutcome($issues === [] ? RunResult::Succeeded : ($checkError instanceof CheckError ? RunResult::Errored : RunResult::Failed), $presented->key, $presented->params, $metrics,
            new UptimeEvidence($status, $issues, $failure?->value, $config['timeout'], $effectiveUrl,
                $config['expected_final_redirect_url'], $config['look_for_string'], $config['absent_string']),
            error: $checkError,
            availabilityFailure: $failure === FetchFailureKind::Tls ? AvailabilityFailure::Tls
                : (in_array('status', $issues, true) ? AvailabilityFailure::fromStatus($status) : null));
    }

    /** @param list<array<string, mixed>> $transfers */
    private function phase(array $transfers, string $end, ?string $start = null, bool $tls = false): ?float
    {
        if ($transfers === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($transfers as $stats) {
            $until = $stats[$end] ?? null;
            $from = $start === null ? 0 : ($stats[$start] ?? null);
            if ((! is_int($until) && ! is_float($until)) || (! is_int($from) && ! is_float($from))) {
                return null;
            }
            // cURL explicitly reports zero when no TLS handshake occurs (HTTP or connection reuse).
            $sum += $tls && $until === 0.0 ? 0.0 : max(0.0, $until - $from);
        }

        return $sum;
    }
}
