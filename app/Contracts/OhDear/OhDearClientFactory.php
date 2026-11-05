<?php

declare(strict_types=1);

namespace Heptapod\Contracts\OhDear;

use OhDear\PhpSdk\OhDear;

/** Builds the Oh Dear SDK client the account import talks to. */
interface OhDearClientFactory
{
    /** @param bool $dryRun Plans and imports may answer differently in the local recorded-fixture client. */
    public function make(#[\SensitiveParameter] string $token, bool $dryRun): OhDear;
}
