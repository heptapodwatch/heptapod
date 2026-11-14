<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
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
     * The spatie meta is {"vendor/package": [{title, link, cve?, severity?, affectedVersions, reportedAt}]}.
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
            foreach ($entries as $entry) {
                $validator = Validator::make(is_array($entry) ? $entry : [], [
                    'title' => ['required', 'string', 'max:1000'],
                    'link' => ['required', 'string', 'max:2048', 'url:http,https'],
                    'cve' => ['sometimes', 'string', 'max:64'],
                    'severity' => ['sometimes', 'string', 'max:32'],
                    'affectedVersions' => ['required', 'string', 'max:1000'],
                    'reportedAt' => ['required', 'date_format:Y-m-d H:i:s'],
                ]);
                if ($validator->fails() || count($advisories) === self::MaxAdvisories) {
                    return [];
                }
                /** @var array{title: string, link: string, cve?: string, severity?: string, affectedVersions: string, reportedAt: string} $advisory */
                $advisory = $validator->validated();
                $advisories[] = new self($package, $advisory['cve'] ?? $advisory['link'], $advisory['title'], $advisory['link'],
                    $advisory['cve'] ?? null, $advisory['severity'] ?? null, $advisory['affectedVersions'],
                    // Packagist reports advisory dates in UTC.
                    CarbonImmutable::createFromFormat('Y-m-d H:i:s', $advisory['reportedAt'], 'UTC')?->toIso8601String()
                        ?? throw new \LogicException('A validated advisory date must parse.'));
            }
        }

        return $advisories;
    }
}
