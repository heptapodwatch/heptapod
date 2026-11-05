<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CheckDetailProvider
{
    /** Null means no accepted observation exists; storage errors must propagate. */
    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?CheckDetailData;
}
