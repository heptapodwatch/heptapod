<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class EmailPageData extends Data
{
    public function __construct(
        public readonly InstanceSettingsData $instancePage,
        public readonly EmailSettingsData $emailPage,
    ) {}
}
