<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProfilePageData extends Data
{
    /** @param list<string> $timezones */
    public function __construct(
        public readonly bool $mustVerifyEmail,
        public readonly ?string $status,
        public readonly array $timezones,
    ) {}
}
