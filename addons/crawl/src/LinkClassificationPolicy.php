<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\Http\AutomatedCheckBlock;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\ConnectionFailureReason;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\TlsFailureReason;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Expression as QueryExpression;

/**
 * Maps a link check result to a stored result label and decides Broken / Not verifiable / Unchecked.
 * External sites often refuse automated checks; only hard evidence (404/410, invalid certificates,
 * other client errors) makes an external link Broken. Internal links keep their earlier rules.
 */
final class LinkClassificationPolicy
{
    public const int VERSION = 1;

    /** Never counted as broken: the link was not checked. */
    public const array UNCHECKED = ['unchecked (timeout)', 'unchecked (connect)', 'unchecked (connection reset)'];

    /** Never counted as broken, internal or external. A same-origin link can redirect to a private target the guard refuses. */
    public const array NOT_VERIFIABLE = ['bot_protection', 'rate_limited', 'private_target'];

    /** Not verifiable on external links; the same cause on an internal link stays broken. */
    public const array EXTERNAL_NOT_VERIFIABLE = ['login_required', 'access_refused', 'dns_unresolved', 'tls_untrusted', 'tls_handshake_failed', 'cause_not_recorded'];

    /** Stored external statuses that policy v1 treats as refusals when the original response is no longer available. */
    public const array LEGACY_REFUSAL_STATUSES = [401, 403, 405, 406, 999];

    /** Not verifiable because the site refused the automated request (presented apart from transport doubts). */
    public const array REFUSALS = ['bot_protection', 'rate_limited', 'login_required', 'access_refused'];

    /** @param array<array<string>> $headers */
    public static function responseLabel(bool $internal, int $status, array $headers, string $bodyPrefix): ?string
    {
        if (! $internal && in_array($status, [404, 410], true)) {
            return 'request_failed';
        }
        $block = AutomatedCheckBlock::reason($status, $headers, $bodyPrefix);
        if ($block instanceof AutomatedCheckBlockReason) {
            return $block->value;
        }
        if ($status < 400) {
            return null;
        }
        if ($internal) {
            return 'request_failed';
        }
        if ($status < 500 && self::externalChallenge($headers, $bodyPrefix)) {
            return 'bot_protection';
        }

        return match ($status) {
            401 => self::authenticationChallenge($headers) ? 'login_required' : 'access_refused',
            403, 405, 406, 999 => 'access_refused',
            default => 'request_failed',
        };
    }

    public static function failureLabel(FetchFailure $failure): string
    {
        return match ($failure->kind) {
            FetchFailureKind::Timeout => 'unchecked (timeout)',
            FetchFailureKind::Connect => $failure->connectionReason === ConnectionFailureReason::Reset ? 'unchecked (connection reset)' : 'unchecked (connect)',
            // The outbound guard refused the request; findings stored before this label keep 'blocked (private target)'.
            FetchFailureKind::PrivateTarget => 'private_target',
            FetchFailureKind::Dns => 'dns_unresolved',
            FetchFailureKind::TooManyRedirects => 'too_many_redirects',
            FetchFailureKind::Tls => match ($failure->tlsReason) {
                TlsFailureReason::Expired, TlsFailureReason::DateInvalid => 'tls_expired',
                TlsFailureReason::NotYetValid => 'tls_not_yet_valid',
                TlsFailureReason::NameMismatch => 'tls_name_mismatch',
                TlsFailureReason::SelfSigned, TlsFailureReason::IncompleteChain => 'tls_untrusted',
                TlsFailureReason::Handshake, null => 'tls_handshake_failed',
            },
            default => 'request_failed',
        };
    }

    /** Relabels a finding from a snapshot without a policy version (stored before VERSION 1) under the current policy.
     * Only the stored status and label decide; headers and bodies were not retained, so vendor rules cannot apply. */
    public static function legacyLabel(?int $status, ?string $label, bool $internal): ?string
    {
        if ($label !== null && $label !== 'request_failed') {
            return $label;
        }
        if ($status === null) {
            return $label === null ? null : 'cause_not_recorded';
        }

        return ! $internal && in_array($status, self::LEGACY_REFUSAL_STATUSES, true) ? 'access_refused' : $label;
    }

    /** SQL condition that marks a crawl_snapshots row as stored before policy versions were recorded.
     * @return literal-string
     */
    public static function legacySql(?int $version): string
    {
        return $version === null ? 'TRUE' : 'FALSE';
    }

    /** Select column `error_message`: the SQL twin of legacyLabel() when $legacy holds, otherwise the stored label.
     * @param  literal-string  $legacy
     * @param  literal-string  $table
     */
    public static function labelSql(string $legacy, string $table = ''): Expression
    {
        return new QueryExpression(self::labelExpression($table, $legacy).' AS error_message');
    }

    /** SQL twin of unchecked() / notVerifiable() / broken(): 'unchecked', 'not_verifiable', 'broken', or NULL for a row that is no finding.
     * @param  literal-string  $table
     * @param  literal-string  $legacy
     * @return literal-string
     */
    public static function statusSql(string $table, string $legacy): string
    {
        $prefix = $table === '' ? '' : $table.'.';
        $label = self::labelExpression($table, $legacy);

        return "(CASE WHEN COALESCE({$label} IN (".self::quoted(self::UNCHECKED)."), FALSE) THEN 'unchecked'"
            .' WHEN COALESCE('.self::notVerifiableCondition($table, $legacy).", FALSE) THEN 'not_verifiable'"
            ." WHEN {$prefix}status_code >= 400 OR {$prefix}status_code IS NULL THEN 'broken' END)";
    }

    /** SQL condition for rows that are not Not verifiable (a NULL label counts as verifiable).
     * @param  literal-string  $legacy
     */
    public static function verifiableSql(string $legacy): Expression
    {
        return new QueryExpression('NOT COALESCE('.self::notVerifiableCondition('', $legacy).', FALSE)');
    }

    public static function unchecked(?string $label): bool
    {
        return in_array($label, self::UNCHECKED, true);
    }

    public static function notVerifiable(?string $label, bool $internal): bool
    {
        return in_array($label, self::NOT_VERIFIABLE, true) || (! $internal && in_array($label, self::EXTERNAL_NOT_VERIFIABLE, true));
    }

    public static function broken(?int $status, ?string $label, bool $internal): bool
    {
        return ! self::unchecked($label) && ! self::notVerifiable($label, $internal) && ($status === null || $status >= 400);
    }

    /** SQL twin of notVerifiable() for crawl_findings rows; $table qualifies the columns, $legacy marks rows without a policy version.
     * @param  literal-string  $table
     * @param  literal-string  $legacy
     */
    public static function notVerifiableSql(string $table = '', string $legacy = 'FALSE'): Expression
    {
        return new QueryExpression(self::notVerifiableCondition($table, $legacy));
    }

    /** SQL twin of broken() for crawl_findings rows.
     * @param  literal-string  $table
     * @param  literal-string  $legacy
     */
    public static function brokenSql(string $table = '', string $legacy = 'FALSE'): Expression
    {
        $prefix = $table === '' ? '' : $table.'.';
        $label = self::labelExpression($table, $legacy);

        return new QueryExpression("(({$prefix}status_code >= 400 OR {$prefix}status_code IS NULL) AND ({$label} IS NULL OR ({$label} NOT IN (".self::quoted(self::UNCHECKED).') AND NOT '.self::notVerifiableCondition($table, $legacy).')))');
    }

    /** @param literal-string $table
     * @param  literal-string  $legacy
     * @return literal-string
     */
    private static function notVerifiableCondition(string $table, string $legacy): string
    {
        $prefix = $table === '' ? '' : $table.'.';
        $label = self::labelExpression($table, $legacy);

        return "({$label} IN (".self::quoted(self::NOT_VERIFIABLE).") OR (NOT {$prefix}internal AND {$label} IN (".self::quoted(self::EXTERNAL_NOT_VERIFIABLE).')))';
    }

    /** @param literal-string $table
     * @param  literal-string  $legacy
     * @return literal-string
     */
    private static function labelExpression(string $table, string $legacy): string
    {
        $prefix = $table === '' ? '' : $table.'.';
        if ($legacy === 'FALSE') {
            return "{$prefix}error_message";
        }
        $statuses = implode(', ', self::LEGACY_REFUSAL_STATUSES);

        return "(CASE WHEN ({$legacy}) AND {$prefix}error_message = 'request_failed' AND {$prefix}status_code IS NULL THEN 'cause_not_recorded'"
            ." WHEN ({$legacy}) AND NOT {$prefix}internal AND ({$prefix}error_message IS NULL OR {$prefix}error_message = 'request_failed') AND {$prefix}status_code IN ({$statuses}) THEN 'access_refused'"
            ." ELSE {$prefix}error_message END)";
    }

    /** Vendor identification headers alone are not challenge evidence; each rule needs a challenge value or block-page content.
     * @param  array<array<string>>  $headers
     */
    private static function externalChallenge(array $headers, string $bodyPrefix): bool
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower((string) $name)] = strtolower(implode(', ', $values));
        }
        $body = strtolower($bodyPrefix);

        return ((isset($normalized['x-datadome']) || str_contains($normalized['server'] ?? '', 'datadome')) && str_contains($body, 'captcha-delivery.com'))
            || in_array($normalized['x-amzn-waf-action'] ?? '', ['captcha', 'challenge'], true)
            || ($normalized['x-vercel-mitigated'] ?? '') === 'challenge'
            || str_contains($body, 'px-captcha')
            || str_contains($body, 'incapsula incident id');
    }

    /** RFC 9110 requires WWW-Authenticate on a real 401; without it the 401 is a plain refusal.
     * @param  array<array<string>>  $headers
     */
    private static function authenticationChallenge(array $headers): bool
    {
        return array_any(array_keys($headers), static fn (int|string $name): bool => strtolower((string) $name) === 'www-authenticate');
    }

    /** Labels are the class constants above, never input.
     * @param  list<string>  $labels
     * @return literal-string
     */
    private static function quoted(array $labels): string
    {
        /** @var literal-string */
        return implode(', ', array_map(static fn (string $label): string => "'".str_replace("'", "''", $label)."'", $labels));
    }
}
