<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ListFilterData extends Data
{
    /** @param list<array{value: string, label: string}> $options */
    public function __construct(
        public readonly FilterKind $kind,
        public readonly string $label,
        public readonly ?string $value,
        public readonly ?string $default,
        public readonly array $options,
        public readonly ?string $empty_label,
        public readonly ?string $option_source,
        public readonly FilterSlot $slot,
        public readonly bool $widens,
        public readonly ?string $disabled_by,
        public readonly ?string $display,
        public readonly ?string $clear_url,
        public readonly ?string $detail_url,
        public readonly ?string $group = null,
        public readonly ?string $prefix = null,
        public readonly ?string $counted_option = null,
        public readonly ?string $counted_label = null,
    ) {}
}
