<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One open advisory reported by spatie/security-advisories-health-check. */
#[TypeScript]
final class SecurityAdvisoryData extends Data
{
    /** The default component name of the spatie check. */
    public const string Component = 'SecurityAdvisories';

    private const int MaxAdvisories = 1000;

    public function __construct(
        public readonly string $package,
        /** The CVE, or the advisory link when the advisory has no CVE. */
        public readonly string $advisory_id,
        public readonly string $title,
        public readonly string $link,
        public readonly ?string $cve,
        public readonly ?string $severity,
        public readonly string $affected_versions,
        public readonly string $reported_at,
    ) {}

    /**
     * The spatie meta is {"vendor/package": [{title, link, cve?, severity?, affectedVersions, reportedAt}]}. Version 1.2 of the
     * check (the last one for PHP 8.2) sends cve and severity as null instead of leaving them out, plus extra keys.
     *
     * Meta of any other shape carries no structured advisories; the component still shows its reported status and message.
     *
     * @param  array<int|string, mixed>  $meta
     * @return list<self>
     */
    public static function fromSpatieMeta(array $meta): array
    {
        $advisories = [];
        foreach ($meta as $package => $entries) {
            if (! is_string($package) || preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~', $package) !== 1 || mb_strlen($package) > 255
                || ! is_array($entries) || ! array_is_list($entries)) {
                return [];
            }
            foreach ($entries as $advisory) {
                if (! is_array($advisory) || ! self::isAdvisory($advisory) || count($advisories) === self::MaxAdvisories) {
                    return [];
                }
                $advisories[] = new self($package, $advisory['cve'] ?? $advisory['link'], $advisory['title'], $advisory['link'],
                    $advisory['cve'] ?? null, $advisory['severity'] ?? null, $advisory['affectedVersions'],
                    // Packagist reports advisory dates in UTC.
                    CarbonImmutable::createFromFormat('Y-m-d H:i:s', $advisory['reportedAt'], 'UTC')?->toIso8601String()
                        ?? throw new \LogicException('A validated advisory date must parse.'));
            }
        }

        return $advisories;
    }

    /**
     * The rules title required|string|max:1000, link required|string|max:2048|url:http,https, cve sometimes|nullable|string|max:64,
     * severity sometimes|nullable|string|max:32, affectedVersions required|string|max:1000 and reportedAt
     * required|date_format:Y-m-d H:i:s, checked the way Laravel's validator checks them. Lists and incident rows present
     * stored advisories on every view; a Validator instance per advisory cost about 0.3 ms each.
     *
     * @param  array<int|string, mixed>  $advisory
     *
     * @phpstan-assert-if-true array{title: string, link: string, cve?: string|null, severity?: string|null, affectedVersions: string, reportedAt: string} $advisory
     */
    private static function isAdvisory(array $advisory): bool
    {
        $required = static fn (string $key, int $max): bool => is_string($advisory[$key] ?? null) && trim($advisory[$key]) !== '' && mb_strlen($advisory[$key]) <= $max;
        // Laravel skips non-implicit rules for a missing, null or blank value.
        $optional = static fn (string $key, int $max): bool => ! isset($advisory[$key])
            || (is_string($advisory[$key]) && (trim($advisory[$key]) === '' || mb_strlen($advisory[$key]) <= $max));
        $reportedAt = $advisory['reportedAt'] ?? null;
        // A null byte makes createFromFormat throw; Laravel's date_format rule fails such a value.
        $date = is_string($reportedAt) && ! str_contains($reportedAt, "\0") ? \DateTime::createFromFormat('!Y-m-d H:i:s', $reportedAt, new \DateTimeZone('UTC')) : false;

        return $required('title', 1000) && $required('link', 2048) && Str::isUrl($advisory['link'], ['http', 'https'])
            && $optional('cve', 64) && $optional('severity', 32) && $required('affectedVersions', 1000)
            && $date !== false && $date->format('Y-m-d H:i:s') === $reportedAt;
    }
}
