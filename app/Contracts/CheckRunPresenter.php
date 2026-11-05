<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CheckRunPresenter
{
    /** Null means this historical run has no facts for a specific presentation; invalid present facts must throw. */
    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData;
}
