<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;
use Illuminate\Support\Facades\DB;

final readonly class DeleteCronTask
{
    public function __construct(private CronTaskStore $store, private RequestEmptyCronRun $request) {}

    public function __invoke(Check $check, int $taskId): void
    {
        DB::transaction(function () use ($check, $taskId): void {
            $query = Check::query()->whereKey($check->id);
            $query->getQuery()->lockForUpdate();
            $query->firstOrFail();
            abort_unless($this->store->find($taskId)->checkId === $check->id, 404);
            $this->store->delete($taskId);
            ($this->request)($check);
        });
    }
}
