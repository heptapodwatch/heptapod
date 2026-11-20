<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A backup in flight (Owner), or the viewer's own one that finished in the last minute. */
#[TypeScript]
final class RunningBackupData extends Data
{
    public function __construct(
        public readonly int $id,
        #[LiteralTypeScriptType("'queued' | 'running' | 'complete' | 'failed'")]
        public readonly string $state,
        public readonly bool $mine,
        public readonly string $requested_at,
        public readonly ?string $started_at,
        public readonly ?string $completed_at,
    ) {}
}
