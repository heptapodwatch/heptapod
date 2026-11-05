<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CronTasksDetailData extends CheckHistoryData
{
    /** @param list<array{id: int, name: string, result: string, type: string, cron_expression: string|null, timezone: string|null, frequency_minutes: int|null, grace_minutes: int, last_ping_at: string|null, last_duration_seconds: float|null, next_due_at: string, ping_url: string, days: list<array{day: string, result: string}>}> $tasks */
    public function __construct(public readonly array $tasks, public readonly bool $more_tasks, public readonly bool $history_truncated)
    {
        parent::__construct(CheckKey::Cron);
    }
}
