<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class FindingsRow extends Data
{
    /** @param list<string> $field_truncation */
    public function __construct(
        public readonly string $row_ref,
        public readonly string $finding_identity,
        public readonly FindingStatus $status,
        public readonly ?string $issue_code,
        public readonly ?int $http_status,
        public readonly ?string $kind,
        public readonly ?bool $internal,
        public readonly string $url,
        public readonly ?string $found_on,
        public readonly ?string $link_text,
        public readonly ?string $error_message,
        public readonly ?FindingCause $cause,
        public readonly ?string $first_seen_at,
        public readonly ?bool $new_since_opening,
        public readonly ?bool $new_since_baseline,
        public readonly ?bool $carried_forward,
        public readonly ?string $source_observed_at,
        /** Set only on a carried-forward row: the cause this run observed instead of confirming the earlier failure. */
        public readonly ?FindingCause $current_observation_cause,
        public readonly array $field_truncation = [],
    ) {}
}
