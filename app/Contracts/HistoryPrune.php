<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * One addon history table that raw retention prunes: rows whose $endedColumn lies before the bound go in batches of
 * $idColumn values. With $baselinePer, the newest accepted row (by run_ended_at, run_id) per value of that column
 * survives, plus, per baseline filter, the newest accepted row matching it.
 */
final readonly class HistoryPrune
{
    /**
     * @param  literal-string  $table
     * @param  literal-string  $endedColumn
     * @param  literal-string  $idColumn
     * @param  ?literal-string  $baselinePer
     * @param  list<Closure(Builder): Builder>  $baselineFilters
     */
    public function __construct(
        public string $table,
        public string $endedColumn = 'run_ended_at',
        public string $idColumn = 'id',
        public ?string $baselinePer = null,
        public array $baselineFilters = [],
    ) {}
}
