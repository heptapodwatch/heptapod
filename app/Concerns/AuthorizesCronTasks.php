<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CronTaskData;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\Gate;

trait AuthorizesCronTasks
{
    private function store(): CronTaskStore
    {
        abort_unless(app()->bound(CronTaskStore::class) && in_array(CheckKey::Cron, app(CheckRegistry::class)->keys(), true), 404);

        return app(CronTaskStore::class);
    }

    private function parent(Site $monitor, string $ability): ?Check
    {
        Gate::authorize($ability, $monitor);
        $this->store();

        return $monitor->checks()->getQuery()->where('type', CheckKey::Cron)->first();
    }

    /** @return array{CronTaskData, Check} */
    private function task(int $id, string $ability): array
    {
        $task = $this->store()->find($id);
        $check = Check::query()->findOrFail($task->checkId);
        Gate::authorize($ability, $check);

        return [$task, $check];
    }
}
