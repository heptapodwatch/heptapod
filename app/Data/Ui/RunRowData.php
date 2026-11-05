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
final class RunRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $check_id,
        public readonly int $site_id,
        public readonly int $client_id,
        public readonly string $site_name,
        public readonly CheckKey $check_type,
        public readonly string $queue,
        public readonly CheckRunStateData $run,
        public readonly string $requested_at,
        public readonly ?string $finished_at,
        public readonly ?int $duration_ms,
        public readonly ?string $note,
        public readonly ?string $summary,
        /** @var array<string, int|float|string|bool|null> */
        public readonly array $summary_params,
        public readonly ?RunResult $result = null,
        public readonly ?string $icon_url = null,
        public readonly SiteIconBacking $icon_backing = SiteIconBacking::None,
        public readonly ?CheckRunPresentationData $presentation = null,
    ) {}
}
