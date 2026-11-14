<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Database\Query\Builder;

/** Application health persistence: open security advisories from each check's latest accepted run, for the Sites filter. */
interface SecurityAdvisoryIndex
{
    /** An index-backed query selecting `check_id` of every check with an open advisory, narrowed to one package or advisory ID (CVE or advisory link). */
    public function affectedChecks(?string $package = null, ?string $advisoryId = null): Builder;
}
