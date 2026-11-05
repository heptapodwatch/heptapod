<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronTaskData;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class SyncCronTasks
{
    public function __construct(private CronTaskStore $store, private EnsureCronCheck $ensure, private RequestEmptyCronRun $request) {}

    /** @param list<array<string, mixed>> $definitions
     * @return list<CronTaskData>
     */
    public function __invoke(Site $site, array $definitions): array
    {
        return DB::transaction(function () use ($site, $definitions): array {
            $check = $definitions === [] ? $site->checks()->getQuery()->where('type', CheckKey::Cron)->first() : ($this->ensure)($site);
            if ($check === null) {
                return [];
            }

            $tasks = $this->store->sync($check->id, $definitions);
            if ($tasks === []) {
                ($this->request)($check);
            }

            return $tasks;
        });
    }
}
