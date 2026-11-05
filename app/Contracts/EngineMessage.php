<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Validation\ValidationException;

/**
 * Catalog of the fixed English messages that Heptapod engines raise as exception messages. New runs record the catalog
 * key as their summary; retained runs and incidents still hold the English text and are mapped here at presentation
 * time. Text that is not in this catalog came from elsewhere and stays untrusted free text.
 * scripts/check-engine-messages.php keeps the catalog complete and translated.
 */
final class EngineMessage
{
    /** Fixed engine exception message => catalog key. */
    public const array Fixed = [
        'Check execution did not complete.' => 'causes.engine.execution_incomplete',
        'Performance recovery margin must be an integer between 0 and 20.' => 'causes.engine.performance_recovery_margin_invalid',
        'Check worker ran out of memory.' => 'causes.engine.worker_memory_exhausted',
        'Check addon is not installed.' => 'causes.engine.addon_missing',
        'TLS target resolution failed.' => 'causes.engine.tls_target_resolution_failed',
        'TLS target resolution timed out.' => 'causes.engine.tls_target_resolution_timeout',
        'TLS verification timed out.' => 'causes.engine.tls_verification_timeout',
        'TLS certificate export failed.' => 'causes.engine.tls_certificate_export_failed',
        'TLS connection timed out.' => 'causes.engine.tls_connection_timeout',
        'TLS connection failed.' => 'causes.engine.tls_connection_failed',
        'DNS resolver output exceeded 128 KiB.' => 'causes.engine.dns_output_too_large',
        'DNS query timed out.' => 'causes.engine.dns_query_timeout',
        'DNS resolver did not respond.' => 'causes.engine.dns_resolver_no_response',
        'DNS query failed.' => 'causes.engine.dns_query_failed',
        'DNS response status is missing.' => 'causes.engine.dns_status_missing',
        'DNS response was truncated.' => 'causes.engine.dns_truncated',
        'DNS response is not authoritative.' => 'causes.engine.dns_not_authoritative',
        'Malformed DNS answer.' => 'causes.engine.dns_malformed_answer',
        'DNS authoritative nameservers could not be determined.' => 'causes.engine.dns_authority_unknown',
        'DNS check deadline exhausted.' => 'causes.engine.dns_deadline_exhausted',
        'WHOIS requires a direct TCP registry destination.' => 'causes.engine.whois_destination_not_direct',
        'WHOIS requires a valid TCP registry destination.' => 'causes.engine.whois_destination_invalid',
        'WHOIS target resolution failed.' => 'causes.engine.whois_target_resolution_failed',
        'WHOIS registry connection failed.' => 'causes.engine.whois_connection_failed',
        'WHOIS query could not be written.' => 'causes.engine.whois_write_failed',
        'WHOIS registry could not use nonblocking reads.' => 'causes.engine.whois_nonblocking_failed',
        'WHOIS registry readiness check failed.' => 'causes.engine.whois_readiness_failed',
        'WHOIS registry read failed.' => 'causes.engine.whois_read_failed',
        'WHOIS response exceeds 256 KiB.' => 'causes.engine.whois_response_too_large',
        'WHOIS decoded response exceeds 256 KiB.' => 'causes.engine.whois_decoded_too_large',
        'WHOIS lookup returned no response.' => 'causes.engine.whois_no_response',
        'WHOIS lookup timed out.' => 'causes.engine.whois_timeout',
        'WHOIS lookup failed.' => 'causes.engine.whois_failed',
        // DnsResolverStatusException: the status is clamped to these values, so the family is closed.
        'DNS resolver returned SERVFAIL.' => 'causes.engine.dns_resolver_servfail',
        'DNS resolver returned REFUSED.' => 'causes.engine.dns_resolver_refused',
        'DNS resolver returned FORMERR.' => 'causes.engine.dns_resolver_formerr',
        'DNS resolver returned NOTIMP.' => 'causes.engine.dns_resolver_notimp',
        'DNS resolver returned an error status.' => 'causes.engine.dns_resolver_error_status',
        'Target DNS lookup failed.' => 'causes.engine.target_dns_failed',
        'Target lookup returned no addresses.' => 'causes.engine.target_no_addresses',
        'registry only offers HTTP WHOIS (unsupported)' => 'causes.engine.whois_http_only',
        'Lighthouse audit did not complete.' => 'causes.engine.lighthouse_incomplete',
        'Lighthouse service returned HTTP 502.' => 'causes.engine.lighthouse_incomplete',
        'Lighthouse was busy; the audit did not run' => 'lighthouse.busy',
        // Before it was fixed English, LighthouseBusyException carried the worker-locale text.
        'Lighthouse war ausgelastet; das Audit wurde nicht ausgeführt' => 'lighthouse.busy',
    ];

    /** Exception class => catalog key, for exceptions whose message is framework text (field paths, rule wording) that must never be shown as a cause. */
    public const array Classes = [
        // A check result that fails its persistence validation is an engine bug, not a site problem.
        ValidationException::class => 'causes.engine.result_invalid',
        TimeoutExceededException::class => 'causes.engine.job_timed_out',
    ];

    /** Catalog key for a check configuration that fails its schema validation at run time. */
    public const string ConfigInvalid = 'causes.engine.config_invalid';

    /**
     * Catalog cause for a recorded run error: its class when the message is framework text, otherwise its engine message.
     *
     * @return array{key: string, params: array<string, int>}|null
     */
    public static function forError(string $class, string $message): ?array
    {
        return isset(self::Classes[$class]) ? ['key' => self::Classes[$class], 'params' => []] : self::summary($message);
    }

    /**
     * Catalog cause for a retained summary that holds engine text: as the `causes.error` message, or (older rows) as
     * the summary itself. Null when the summary is not engine text.
     *
     * @param  array<array-key, mixed>  $params
     * @return array{key: string, params: array<string, int>}|null
     */
    public static function fromSummary(string $key, array $params): ?array
    {
        $text = $key === 'causes.error' ? ($params['message'] ?? null) : (SummaryKey::isFreeText($key) ? $key : null);

        return is_string($text) ? self::summary($text) : null;
    }

    /**
     * Output-boundary form of a retained summary: engine text becomes its catalog cause, anything else is unchanged.
     * Every path that renders or returns a retained summary key/params calls this (or CauseSummary, which does).
     *
     * @param  array<string, string|int|float|bool|null>  $params
     * @return array{key: string, params: array<string, string|int|float|bool|null>}
     */
    public static function normalize(string $key, array $params): array
    {
        return self::fromSummary($key, $params) ?? ['key' => $key, 'params' => $params];
    }

    /**
     * Catalog cause for an engine message, or null for text the engine did not write.
     *
     * @return array{key: string, params: array<string, int>}|null
     */
    public static function summary(string $message): ?array
    {
        // FetchFailure::detail() format, written by the outbound HTTP boundary.
        // CrawlTargetUnavailable prefixes the same detail.
        if (preg_match('/^(?:Crawl start page unavailable\. )?Outbound HTTP failed: ([a-z_]+)(?: \(HTTP (\d{3})\))?\.$/D', $message, $match) === 1) {
            $kind = FetchFailureKind::tryFrom($match[1]);

            return $kind instanceof FetchFailureKind ? new FetchFailure($kind, isset($match[2]) ? (int) $match[2] : null)->summary() : null;
        }
        // Retained uptime summaries from before catalog keys: the "causes.timeout" text with its configured seconds.
        if (preg_match('/^Timed out after (\d{1,4}) s\.?$/D', $message, $match) === 1) {
            return ['key' => 'causes.timeout', 'params' => ['seconds' => (int) $match[1]]];
        }
        $key = self::Fixed[$message] ?? null;

        return $key === null ? null : ['key' => $key, 'params' => []];
    }
}
