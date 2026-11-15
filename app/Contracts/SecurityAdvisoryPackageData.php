<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A package with open security advisories: the number of affected sites and its highest known severity. */
#[TypeScript]
final class SecurityAdvisoryPackageData extends Data
{
    public function __construct(
        public readonly string $package,
        public readonly int $sites,
        public readonly ?string $severity,
    ) {}
}
