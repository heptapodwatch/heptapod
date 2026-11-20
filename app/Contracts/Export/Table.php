<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Export;

/**
 * One exportable dataset. It exists only after the controller has authorized the request, and every value is final
 * text (localized, redacted, timezone-formatted), so a writer only encodes.
 */
final readonly class Table
{
    /** Rows written per export; Markdown and plain text then say "first N of M". */
    public const int RowLimit = 10000;

    /**
     * @param  list<array{label: string, value: string}>  $facts  header lines (Site, Check, Status …)
     * @param  list<Column>  $columns
     * @param  iterable<list<string>>  $rows  lazy, one cell per column; read inside the export's transaction
     * @param  string  $filename  stem without extension
     * @param  int|null  $total  the row count when it is known up front; null lets the writer count rows beyond the limit
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
