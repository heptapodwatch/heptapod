<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\LighthouseReport;
use Heptapod\Contracts\TargetAvailabilityException;
use Heptapod\Contracts\TlsFailureReason;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

final readonly class HttpChromiumRenderer implements ChromiumRenderer
{
    private const float CleanupReserve = 5.0;

    private const float MinimumRequest = 0.001;

    public function __construct(private OutboundHttp $http, private ?CertificateFetcher $certificates = null) {}

    public function lighthouse(string $url, CheckContext $context, array $options = []): LighthouseReport
    {
        $serviceUrl = config('lighthouse.service_url');
        $controlUrl = config('lighthouse.proxy_control_url');
        if (! is_string($serviceUrl) || ! is_string($controlUrl)) {
            throw new \InvalidArgumentException('Lighthouse service and proxy control URLs must be configured.');
        }
        $busy = false;
        while (true) {
            $context->runControl?->assertActive();
            $usable = $this->remaining($context) - self::CleanupReserve - self::MinimumRequest;
            if ($usable <= self::MinimumRequest) {
                throw $busy ? new LighthouseBusyException : new FetchException(new FetchFailure(FetchFailureKind::Timeout));
            }
            // Busy responses never start an audit. Each admission gets a fresh 160-second proxy scope; the request ends after 155 seconds.
            $scope = $this->fetchService(new FetchRequest(rtrim($controlUrl, '/').'/scopes', method: 'POST',
                headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                body: json_encode(['siteHost' => TargetHost::fromUrl($context->url)], JSON_THROW_ON_ERROR),
                maxWireBytes: 4096, maxDecodedBytes: 4096, timeoutSeconds: min(5.0, $usable)), $context);
            $decodedScope = json_decode($scope->body, true, flags: JSON_THROW_ON_ERROR);
            $token = is_array($decodedScope) ? ($decodedScope['token'] ?? null) : null;
            if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                throw new \UnexpectedValueException('Proxy control returned an invalid scope.');
            }
            try {
                $context->runControl?->assertActive();
                $auditBudget = min(155.0, $this->remaining($context) - self::CleanupReserve);
                if ($auditBudget < self::MinimumRequest) {
                    throw $busy ? new LighthouseBusyException : new FetchException(new FetchFailure(FetchFailureKind::Timeout));
                }
                $response = $this->http->fetch(new FetchRequest(
                    rtrim($serviceUrl, '/').'/lighthouse', method: 'POST',
                    headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    body: json_encode(['url' => $url, 'options' => $options, 'proxyToken' => $token], JSON_THROW_ON_ERROR),
                    maxWireBytes: 8 * 1024 * 1024, maxDecodedBytes: 8 * 1024 * 1024, timeoutSeconds: $auditBudget,
                ), $context);
                if (! $response->failure instanceof FetchFailure) {
                    $report = json_decode($response->body, flags: JSON_THROW_ON_ERROR);
                    $targetStatus = $report instanceof \stdClass ? ($report->target_status_code ?? null) : null;
                    if ($report instanceof \stdClass && (isset($report->runtimeError) || (is_int($targetStatus) && $targetStatus >= 400))) {
                        if (! is_string($report->target_host ?? null)) {
                            throw new \UnexpectedValueException('Lighthouse returned no navigation host for a failed audit.');
                        }
                        $host = TargetHost::normalize($report->target_host);
                        $verdict = $this->fetchService(new FetchRequest(rtrim($controlUrl, '/').'/scopes/'.$token.'?host='.rawurlencode($host),
                            maxWireBytes: 4096, maxDecodedBytes: 4096, timeoutSeconds: min(5.0, $this->remaining($context))), $context);
                        $policy = json_decode($verdict->body, true, flags: JSON_THROW_ON_ERROR);
                        if (! is_array($policy) || ! is_bool($policy['blockedTarget'] ?? null)) {
                            throw new \UnexpectedValueException('Proxy control returned an invalid policy verdict.');
                        }
                        if ($policy['blockedTarget']) {
                            $address = $policy['targetAddress'] ?? null;
                            $class = $policy['targetClass'] ?? null;
                            if (! is_string($address) || ! is_string($class)) {
                                throw new \UnexpectedValueException('Proxy control returned no typed refusal cause.');
                            }
                            throw new FetchException(new FetchFailure(FetchFailureKind::PrivateTarget,
                                targetAddress: $address === '' ? null : $address, targetClass: $class,
                                redirected: $host !== TargetHost::fromUrl($url)));
                        }
                    }
                    break;
                }
                if ($response->failure->kind !== FetchFailureKind::HttpStatus || $response->failure->status !== 429) {
                    if ($response->failure->kind === FetchFailureKind::HttpStatus) {
                        if ($response->failure->status === 502) {
                            throw new LighthouseAuditIncompleteException('Lighthouse audit did not complete.');
                        }
                        throw new \UnexpectedValueException('Lighthouse service returned HTTP '.$response->failure->status.'.');
                    }
                    $this->throwServiceFailure($response->failure);
                }
                $busy = true;
            } finally {
                $release = $this->http->fetch(new FetchRequest(rtrim($controlUrl, '/').'/scopes/'.$token, method: 'DELETE',
                    maxWireBytes: 4096, maxDecodedBytes: 4096, timeoutSeconds: self::CleanupReserve), $context);
                if ($release->failure instanceof FetchFailure) {
                    // Fail closed: a live scope is our proxy's fault, not the site's.
                    throw new LighthouseScopeReleaseFailed('Lighthouse proxy scope could not be released.');
                }
            }
            $context->runControl?->assertActive();
            $waitMicroseconds = (int) floor(min(1.0, $this->remaining($context) - self::CleanupReserve - 2 * self::MinimumRequest) * 1_000_000);
            if ($waitMicroseconds < 1) {
                throw new LighthouseBusyException;
            }
            Sleep::usleep($waitMicroseconds);
        }
        $decoded = json_decode($response->body, flags: JSON_THROW_ON_ERROR);
        if ($decoded instanceof \stdClass && property_exists($decoded, 'load_timed_out') && ! is_bool($decoded->load_timed_out)) {
            throw new \UnexpectedValueException('Invalid Lighthouse load timeout flag.');
        }
        $targetStatus = $decoded instanceof \stdClass ? ($decoded->target_status_code ?? null) : null;
        if ($targetStatus !== null && (! is_int($targetStatus) || $targetStatus < 100 || $targetStatus > 599)) {
            throw new \UnexpectedValueException('Invalid Lighthouse target status.');
        }
        if (AvailabilityFailure::fromStatus($targetStatus) instanceof AvailabilityFailure) {
            throw new TargetAvailabilityException(new FetchFailure(FetchFailureKind::HttpStatus, $targetStatus));
        }
        if ($decoded instanceof \stdClass && property_exists($decoded, 'auditFailure')) {
            $this->auditFailure($decoded->auditFailure);
        }
        if ($decoded instanceof \stdClass && ($decoded->runtimeError ?? null) instanceof \stdClass) {
            $code = $decoded->runtimeError->code ?? null;
            $kind = match ($code) {
                'ERR_CERT_COMMON_NAME_INVALID', 'ERR_CERT_DATE_INVALID', 'ERR_CERT_AUTHORITY_INVALID', 'ERR_SSL_PROTOCOL_ERROR' => FetchFailureKind::Tls,
                'ERR_NAME_NOT_RESOLVED' => FetchFailureKind::Dns,
                'ERR_CONNECTION_REFUSED' => FetchFailureKind::Connect,
                'ERR_TIMED_OUT', 'ERR_CONNECTION_TIMED_OUT' => FetchFailureKind::Timeout,
                default => null,
            };
            if (! $kind instanceof FetchFailureKind) {
                if ($code === 'AUDIT_FAILED') {
                    throw new LighthouseAuditIncompleteException('Lighthouse audit did not complete.');
                }
                $this->auditFailure($decoded->runtimeError);
            }
            $tlsSummary = null;
            if ($kind === FetchFailureKind::Tls) {
                $host = TargetHost::fromUrl($url);
                $nativeReason = TlsFailureReason::fromNativeError($code);
                $tlsSummary = $nativeReason->summary($host);
                // Optional diagnostics cannot extend the audit budget or change the native observation.
                if (! $this->certificates instanceof CertificateFetcher) {
                    Log::notice('Certificate diagnostics unavailable; native TLS reason retained.', [
                        'check_id' => $context->checkId, 'run_id' => $context->runId,
                        'diagnostics_available' => false, 'native_reason' => $nativeReason->value,
                        'summary' => $tlsSummary['key'],
                    ]);
                } elseif ($this->remaining($context) >= 10.0) {
                    try {
                        $tlsSummary = $this->certificates->fetch($host, (int) (parse_url($url, PHP_URL_PORT) ?? 443), TargetHost::fromUrl($context->url))->failureSummary($nativeReason);
                    } catch (FetchException $exception) {
                        // A refused optional capture must not replace the primary navigation failure.
                        Log::notice('Certificate diagnostics refused; native navigation reason retained.', [
                            'check_id' => $context->checkId, 'run_id' => $context->runId,
                            'diagnostic_failure' => $exception->failure->kind->value,
                        ]);
                    } catch (ExternalNetworkException|ExternalTimeoutException) {
                        // Keep the native navigation reason when no extra certificate facts can be captured.
                    }
                }
            }
            throw new TargetAvailabilityException(new FetchFailure($kind, tlsSummary: $tlsSummary));
        }
        if (! $decoded instanceof \stdClass || ! is_string($decoded->finalDisplayedUrl ?? $decoded->finalUrl ?? null)) {
            throw new \UnexpectedValueException('Lighthouse service returned an invalid report.');
        }
        /** @var array<string, mixed> $raw */
        $raw = (array) $decoded;
        /** @var string $finalUrl */
        $finalUrl = $decoded->finalDisplayedUrl ?? $decoded->finalUrl;

        return new LighthouseReport($raw, $finalUrl);
    }

    private function auditFailure(mixed $failure): never
    {
        if (! $failure instanceof \stdClass || ! is_string($failure->code ?? null)
            || ! ($code = LighthouseFailureCode::tryFrom($failure->code)) instanceof LighthouseFailureCode) {
            throw new \UnexpectedValueException('Invalid Lighthouse failure code.');
        }
        $stage = $failure->stage ?? null;
        $message = $failure->message ?? null;
        $seconds = $failure->budgetSeconds ?? null;
        $lastUnrecognisedEvent = null;
        if (property_exists($failure, 'last_unrecognised_event')) {
            $lastUnrecognisedEvent = $failure->last_unrecognised_event;
            if (! is_string($lastUnrecognisedEvent) || mb_strlen($lastUnrecognisedEvent) > 64
                || preg_match('/^[A-Za-z][A-Za-z _-]*$/D', $lastUnrecognisedEvent) !== 1) {
                throw new \UnexpectedValueException('Invalid Lighthouse unrecognised event.');
            }
        }
        if ($stage !== null && (! is_string($stage) || ! in_array($stage, ['browser_launch', 'navigation', 'gathering', 'auditing', 'reporting', 'unknown'], true))) {
            throw new \UnexpectedValueException('Invalid Lighthouse failure stage.');
        }
        if ($message !== null && (! is_string($message) || mb_strlen($message) > 500)) {
            throw new \UnexpectedValueException('Invalid Lighthouse failure message.');
        }
        if ($code === LighthouseFailureCode::AuditBudgetExceeded && (! is_int($seconds) || $seconds <= 0)) {
            throw new \UnexpectedValueException('Invalid Lighthouse audit budget.');
        }
        if ($code !== LighthouseFailureCode::AuditBudgetExceeded && $seconds !== null) {
            throw new \UnexpectedValueException('Unexpected Lighthouse audit budget.');
        }

        $stageSeconds = [];
        if (property_exists($failure, 'stageSeconds')) {
            if (! $failure->stageSeconds instanceof \stdClass) {
                throw new \UnexpectedValueException('Invalid Lighthouse stage seconds.');
            }
            foreach (get_object_vars($failure->stageSeconds) as $key => $value) {
                if (! in_array($key, ['browser_launch', 'navigation', 'gathering', 'auditing', 'reporting', 'unknown'], true)
                    || ! is_int($value) || $value < 0 || $value > 155) {
                    throw new \UnexpectedValueException('Invalid Lighthouse stage seconds.');
                }
                $stageSeconds[$key] = $value;
            }
        }

        throw new LighthouseAuditFailed($code, $stage, $message, $seconds, $lastUnrecognisedEvent, $stageSeconds);
    }

    private function throwServiceFailure(FetchFailure $failure): never
    {
        if (in_array($failure->kind, [FetchFailureKind::Connect, FetchFailureKind::Dns, FetchFailureKind::Timeout], true)) {
            throw new LighthouseServiceUnavailable('Lighthouse service could not be reached.');
        }

        if ($failure->kind === FetchFailureKind::HttpStatus) {
            throw new LighthouseAuditFailed(LighthouseFailureCode::AuditIncomplete, diagnostic: 'proxy control HTTP '.$failure->status);
        }

        throw new FetchException($failure);
    }

    private function fetchService(FetchRequest $request, CheckContext $context): FetchResult
    {
        $result = $this->http->fetch($request, $context);
        if ($result->failure instanceof FetchFailure) {
            if (! in_array($result->failure->kind, [FetchFailureKind::Connect, FetchFailureKind::Dns, FetchFailureKind::Timeout, FetchFailureKind::HttpStatus], true)) {
                // Proxy control is our service: any other failure kind must not read as a site fault.
                throw new LighthouseAuditFailed(LighthouseFailureCode::AuditIncomplete, diagnostic: 'proxy control '.$result->failure->kind->value);
            }
            $this->throwServiceFailure($result->failure);
        }

        return $result;
    }

    private function remaining(CheckContext $context): float
    {
        return ($context->deadline->getTimestampMs() - CarbonImmutable::now()->getTimestampMs()) / 1000;
    }
}
