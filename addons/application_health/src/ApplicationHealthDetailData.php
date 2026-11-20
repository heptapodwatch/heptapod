<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\PaginationData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ApplicationHealthDetailData extends CheckHistoryData
{
    /**
     * @param  list<array{name: string, label: string, status: string, short_summary: string, latest_result: string}>  $components
     * @param  list<array{run_id: int, ended_at: string, result: string, reason: string|null, http_status: int|null, response_time_ms: float|null}>  $attempts
     * @param  list<SecurityAdvisoryData>  $advisories
     */
    public function __construct(
        public readonly ?int $last_valid_run_id,
        public readonly ?string $last_valid_at,
        public readonly bool $stale,
        public readonly array $components,
        public readonly PaginationData $components_pagination,
        public readonly array $attempts,
        public readonly array $advisories,
        public readonly PaginationData $advisories_pagination,
    ) {
        parent::__construct(CheckKey::ApplicationHealth);
    }
}
