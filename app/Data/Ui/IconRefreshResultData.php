<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IconRefreshResultData extends Data
{
    /** @param array<string, string|int|float|bool|null> $params */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {}
}
