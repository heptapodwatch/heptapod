<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\ImportRunStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ImportOhDearRunPageData extends Data
{
    /**
     * @param  array{id: string, status: ImportRunStatus, expected: int, attempts: int, processed: int, rows: list<array<string, mixed>>, remote_error: string|null, error: string|null, failed: int, failed_earlier: int, started_at: string|null, finished_at: string|null, can_retry: bool, cron: list<array{site: string, site_id: int, tasks: list<array{name: string, ping_url: string}>}>}  $importRun
     */
    public function __construct(
        public readonly array $importRun,
    ) {}
}
