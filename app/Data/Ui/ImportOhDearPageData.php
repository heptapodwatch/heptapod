<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ImportOhDearPageData extends Data
{
    /** @param list<array{id: int, name: string, is_internal: bool}> $clients */
    public function __construct(
        public readonly array $clients,
    ) {}
}
