<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A manual check run in flight, or the viewer's own one that finished in the last minute (then with its result). */
#[TypeScript]
final class RunningCheckRunData extends Data
{
    public function __construct(
        public readonly int $check_id,
        public readonly int $site_id,
        public readonly string $site_name,
        public readonly CheckKey $check_type,
        public readonly CheckRunStateData $run,
        public readonly bool $mine,
        public readonly ?RunResult $result,
        public readonly ?string $finished_at,
    ) {}
}
