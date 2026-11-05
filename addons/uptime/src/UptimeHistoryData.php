<?php

declare(strict_types=1);

namespace Heptapod\Addons\Uptime;

use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UptimeHistoryData extends CheckHistoryData
{
    /**
     * @param  list<array{run_id: int, ended_at: string, result: string, status_code: int|null, status_text: string|null, reasons: list<string>, total_time_ms: float|null}>  $runs
     * @param  list<array{run_id: int, ended_at: string, result: string, status_code: int|null, status_text: string|null, reasons: list<string>, total_time_ms: float|null}>  $recent_failures
     */
    public function __construct(public readonly array $runs, public readonly array $recent_failures)
    {
        parent::__construct(CheckKey::Uptime);
    }
}
