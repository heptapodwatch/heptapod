<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\PaginationData;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Enums\ImportSection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ImportOhDearRunPageData extends Data
{
    /**
     * @param  array{id: string, status: ImportRunStatus, expected: int, attempts: int, processed: int, rows: list<array<string, mixed>>, rows_pagination: PaginationData, common_notices: list<string>, alert_timing: array{inherited: int, overridden: int}, remote_error: string|null, error: string|null, failed: int, failed_remote: int, failed_earlier: int, started_at: string|null, finished_at: string|null, can_retry: bool, sections: list<ImportSection>, cron: list<array{site: string, site_id: int, tasks: list<array{name: string, ping_url: string}>}>, cron_pagination: PaginationData, channels: list<array<string, mixed>>}  $importRun
     */
    public function __construct(
        public readonly array $importRun,
    ) {}
}
