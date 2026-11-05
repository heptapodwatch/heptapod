<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DnsComparisonData extends CheckHistoryData
{
    /**
     * @param  array<string, list<array<string, int|string>>>  $accepted_records
     * @param  array<string, list<array<string, int|string>>>  $current_records
     * @param  array<string, array{added: list<array<string, int|string>>, removed: list<array<string, int|string>>}>  $changes
     * @param  list<array{type: string, host: string, record_type: string|null}>  $current_issues
     * @param  list<array{run_id: int, observed_at: string, diff_summary: string, initial: bool}>  $timeline
     */
    public function __construct(
        public readonly ?int $accepted_run_id,
        public readonly ?string $accepted_at,
        public readonly ?string $accepted_by,
        public readonly bool $accept_would_change_baseline,
        public readonly array $current_issues,
        public readonly array $accepted_records,
        public readonly ?int $current_run_id,
        public readonly array $current_records,
        public readonly array $changes,
        public readonly bool $records_truncated,
        public readonly array $timeline,
    ) {
        parent::__construct(CheckKey::Dns);
    }
}
