<?php

namespace Heptapod\Console\Commands;

use Heptapod\Actions\GenerateMaintenancePeriods;
use Heptapod\Models\RecurringMaintenancePeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Generate upcoming occurrences of recurring maintenance windows')]
#[Signature('heptapod:generate-maintenance')]
final class GenerateMaintenancePeriodsCommand extends Command
{
    public function handle(GenerateMaintenancePeriods $generate): int
    {
        foreach (RecurringMaintenancePeriod::query()->lazyById() as $period) {
            $generate($period);
        }

        return self::SUCCESS;
    }
}
