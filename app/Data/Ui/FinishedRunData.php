<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\SiteIconBacking;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class FinishedRunData extends Data
{
    /** @param array<string, int|float|string|bool|null> $summary_params */
    public function __construct(
        public readonly int $id,
        public readonly int $check_id,
        public readonly int $site_id,
        public readonly string $site_name,
        public readonly CheckKey $check_type,
        public readonly RunResult $result,
        public readonly string $ended_at,
        public readonly ?int $duration_ms,
        public readonly ?string $summary,
        public readonly array $summary_params,
        public readonly ?CheckRunPresentationData $presentation = null,
        public readonly ?string $icon_url = null,
        public readonly SiteIconBacking $icon_backing = SiteIconBacking::None,
        /** The attempt that recorded this run; set where the reader links it (the Runs board). */
        public readonly ?int $attempt_id = null,
    ) {}
}
