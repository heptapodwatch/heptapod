<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CheckRunPresenter
{
    /** Null means this historical run has no facts for a specific presentation; invalid present facts must throw. */
    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData;

    /** Whether the headline text for this key already names the check, so headlines do not prefix the check label. */
    public function namesCheck(string $key): bool;
}
