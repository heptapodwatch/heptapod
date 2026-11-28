<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Schema;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Rendering hints are optional and never define validation. */
#[TypeScript]
final class FieldPresentation extends Data
{
    /** @param array<string, string> $options Wire value => translation key. */
    public function __construct(
        public readonly string $labelKey,
        public readonly ?string $helpKey = null,
        public readonly array $options = [],
        public readonly string|Optional $group = new Optional,
        public readonly bool|Optional $hidden = new Optional,
        public readonly string|Optional $unit = new Optional,
    ) {}
}
