<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Findings\AvailableFindingsSnapshot;
use Heptapod\Contracts\Findings\FindingsFilter;
use Heptapod\Contracts\Findings\FindingsPage;
use Heptapod\Contracts\Findings\FindingsPosition;
use Heptapod\Contracts\Findings\UnavailableFindingsSnapshot;

interface FindingsQueryProvider
{
    public function snapshot(CheckKey $type, int $checkId, RunReference $run): AvailableFindingsSnapshot|UnavailableFindingsSnapshot;

    /** Must reread and share-lock source and baseline in the caller's short page transaction. */
    public function page(AvailableFindingsSnapshot $snapshot, FindingsFilter $filter, ?AvailableFindingsSnapshot $baseline, ?FindingsPosition $after, int $limit): FindingsPage|UnavailableFindingsSnapshot;
}
