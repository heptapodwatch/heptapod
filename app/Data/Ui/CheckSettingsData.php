<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Schema\Schema;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One stored check as its settings sheet edits it; shared by the check tab and the site settings Checks section. */
#[TypeScript]
final class CheckSettingsData extends Data
{
    public function __construct(
        public readonly int $site_id,
        public readonly int $check_id,
        public readonly CheckKey $check_key,
        public readonly string $label_key,
        public readonly bool $enabled,
        public readonly int $interval_seconds,
        public readonly Schema $config_schema,
        /** Masked check config; an object so an empty config still serializes as {}. */
        #[LiteralTypeScriptType('object')]
        public readonly \stdClass $settings,
        public readonly bool $can_update,
    ) {}
}
