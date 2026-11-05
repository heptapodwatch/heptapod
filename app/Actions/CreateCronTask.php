<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CronTaskData;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class CreateCronTask
{
    public function __construct(private CronTaskStore $store, private EnsureCronCheck $ensure) {}

    /** @param array<string, mixed> $values */
    public function __invoke(Site $site, array $values): CronTaskData
    {
        return DB::transaction(function () use ($site, $values): CronTaskData {
            $check = ($this->ensure)($site);

            return $this->store->create($check->id, $values);
        });
    }
}
