<?php

namespace Heptapod\Contracts\OhDear;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MonitorData extends Data
{
    public readonly string $type;

    /** @param list<string> $tags
     * @param  list<CheckData>  $checks
     * @param  array<string, mixed>  $uptime_check_settings  Authorized API projection only, not generic UI props.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $team_id,
        public readonly string $url,
        public readonly bool $uses_https,
        public readonly string $sort_url,
        public readonly string $label,
        public readonly string $group_name,
        public readonly array $tags,
        public readonly ?string $description,
        public readonly ?string $notes,
        public readonly ?string $latest_run_date,
        public readonly CheckResult $summarized_check_result,
        public readonly array $checks,
        public readonly array $uptime_check_settings,
        public readonly string $badge_id,
        public readonly ?string $marked_for_deletion_at,
        public readonly string $created_at,
        public readonly string $updated_at,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $certificate_health_check_settings = new Optional,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $broken_links_check_settings = new Optional,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $dns_check_settings = new Optional,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $lighthouse_check_settings = new Optional,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $application_health_check_settings = new Optional,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $domain_check_settings = new Optional,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $performance_check_settings = new Optional,
        /** @var array<string, mixed>|null|Optional */
        public readonly array|null|Optional $sitemap_check_settings = new Optional,
        /** @var list<array{name: string, value: string}>|null|Optional */
        public readonly array|null|Optional $crawler_headers = new Optional,
        /** @var list<string>|null|Optional */
        public readonly array|null|Optional $send_report_to_emails = new Optional,
        /** @var list<CheckKey>|null|Optional */
        public readonly array|null|Optional $include_check_types_in_report = new Optional,
    ) {
        $this->type = 'http';
    }
}
