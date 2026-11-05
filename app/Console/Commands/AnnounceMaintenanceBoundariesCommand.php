<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\AnnounceMaintenanceBoundaries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('heptapod:announce-maintenance-boundaries')]
#[Description('Announce maintenance windows whose start or end boundary has passed')]
final class AnnounceMaintenanceBoundariesCommand extends Command
{
    public function handle(AnnounceMaintenanceBoundaries $announce): int
    {
        $announce();

        return self::SUCCESS;
    }
}
