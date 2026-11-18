<?php

declare(strict_types=1);

namespace Heptapod\Data\Import;

use Heptapod\Enums\ImportSection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Dry-run plan or import result for one source account. A remote error means the listed rows are incomplete. */
#[TypeScript]
final class ImportPlanData extends Data
{
    /**
     * @param  list<ImportSection>  $sections
     * @param  list<array{id: int, name: string}>  $teams
     * @param  list<ImportPlanRowData>  $rows
     * @param  list<ImportChannelRowData>  $channels
     */
    public function __construct(
        public readonly bool $dry_run,
        public readonly array $sections,
        public readonly array $teams,
        public readonly array $rows,
        public readonly ?string $remote_error,
        public readonly array $channels = [],
    ) {}

    public function failed(): bool
    {
        return $this->remote_error !== null || array_any($this->rows, static fn (ImportPlanRowData $row): bool => $row->failed());
    }
}
