<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The saved email appearance of Settings → Email; logos as owner-only URLs, which the page reads into its draft. */
#[TypeScript]
final class EmailSettingsData extends Data
{
    public function __construct(
        public readonly ?string $sender_name,
        public readonly ?string $accent,
        public readonly ?string $footer,
        public readonly ?string $logo_url,
        public readonly ?string $logo_dark_url,
        public readonly int $sender_name_max,
        public readonly int $footer_max,
        public readonly int $logo_max_kilobytes,
    ) {}
}
