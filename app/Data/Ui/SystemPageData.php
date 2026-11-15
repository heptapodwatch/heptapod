<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A closure, so the shell's partial reloads on this page do not read system health again. */
#[TypeScript]
final class SystemPageData extends Data
{
    public function __construct(
        public readonly ClosureLazy|SystemHealthPageData $systemPage,
    ) {}
}
