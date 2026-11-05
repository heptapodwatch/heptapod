<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CronTaskStore;

final readonly class RecordCronPing
{
    public function __construct(private CronTaskStore $tasks) {}

    /** @param array{runtime?: int|float, exit_code?: int|string, memory?: int|float, failure_message?: string|null} $values */
    public function __invoke(string $uuid, string $phase, array $values): void
    {
        $this->tasks->recordPing($uuid, $phase, $values);
    }
}
