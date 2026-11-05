<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\RunPhase;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckRunStateData extends Data
{
    public function __construct(
        public readonly int $check_id,
        public readonly RunPhase $phase,
        public readonly ?int $attempt_id,
        public readonly ?string $queue,
        public readonly ?int $queue_position,
        public readonly ?int $progress_n,
        public readonly ?int $progress_total,
        public readonly ?string $phase_label,
        public readonly ?string $started_at,
        public readonly ?string $actor,
        public readonly ?string $due_at,
        public readonly bool $can_cancel,
        public readonly ?string $requested_at = null,
    ) {}
}
