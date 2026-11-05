<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CronTaskData;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCronTask
{
    public function __construct(private CronTaskStore $store) {}

    /** @param array<string, mixed> $values */
    public function __invoke(Check $check, int $taskId, array $values): CronTaskData
    {
        return DB::transaction(function () use ($check, $taskId, $values): CronTaskData {
            $query = Check::query()->whereKey($check->id);
            $query->getQuery()->lockForUpdate();
            $query->firstOrFail();
            abort_unless($this->store->find($taskId)->checkId === $check->id, 404);

            return $this->store->update($taskId, $values);
        });
    }
}
