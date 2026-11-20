<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\ImportRunStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An Oh Dear import in flight (Owner/Admin), or the viewer's own one that finished in the last minute. */
#[TypeScript]
final class RunningImportData extends Data
{
    public function __construct(
        public readonly string $id,
        /** "Run again" reuses the id; the attempt tells its result apart from the previous one. */
        public readonly int $attempts,
        public readonly ImportRunStatus $status,
        public readonly bool $mine,
        public readonly string $requested_at,
        public readonly ?string $started_at,
        public readonly ?string $finished_at,
    ) {}
}
