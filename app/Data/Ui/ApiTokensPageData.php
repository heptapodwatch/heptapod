<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ApiTokensPageData extends Data
{
    public function __construct(
        public readonly ApiTokenSettingsData $tokensPage,
    ) {}
}
