<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Export;

/**
 * One exportable dataset. It exists only after the controller has authorized the request, and every value is final
 * text (localized, redacted, timezone-formatted), so a writer only encodes.
 */
final readonly class Table
{
    /** Rows one Markdown or plain-text export holds (it then says "first N of M"); CSV refuses a known total above it. */
    public const int RowLimit = 10000;

    /**
     * @param  list<array{label: string, value: string}>  $facts  header lines (Site, Check, Status …)
     * @param  list<Column>  $columns
     * @param  iterable<list<string>>  $rows  lazy, one cell per column; read inside the export's transaction
     * @param  string  $filename  stem without extension
     * @param  int|null  $total  the row count when it is known up front; null for a retained snapshot, which CSV exports in full
     */
    public function __construct(
        public string $title,
        public array $facts,
        public array $columns,
        public iterable $rows,
        public string $filename,
        public ?int $total = null,
    ) {}
}
