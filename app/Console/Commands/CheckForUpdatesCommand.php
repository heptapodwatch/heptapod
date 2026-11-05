<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\CheckForUpdates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Check the configured release source for a newer Heptapod version')]
#[Signature('heptapod:check-updates')]
final class CheckForUpdatesCommand extends Command
{
    public function handle(CheckForUpdates $check): int
    {
        $check();

        return self::SUCCESS;
    }
}
