<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunPhase;

final readonly class RunsFilterData
{
    public function __construct(
        public ?int $client,
        public ?int $check,
        public ?CheckKey $type,
        public ?RunPhase $state,
        public ?int $before,
        public bool $show_same_result,
        public string $period,
        public int $per_page = 50,
        /** Only runs a person started (Runs "Started manually", `?started=manual`). */
        public bool $started_manually = false,
    ) {}
}
