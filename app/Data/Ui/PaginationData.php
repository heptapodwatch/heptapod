<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PaginationData extends Data
{
    public function __construct(
        public readonly int $current_page,
        public readonly int $per_page,
        public readonly int $total,
        public readonly int $last_page,
        public readonly ?string $next_page_url,
        public readonly ?string $previous_page_url,
    ) {}
}
