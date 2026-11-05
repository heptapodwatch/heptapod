<?php

namespace Heptapod\Console\Commands;

use Heptapod\SystemHealth\ReadSystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Print instance system health as JSON')]
#[Signature('heptapod:system-health')]
final class SystemHealthCommand extends Command
{
    public function handle(ReadSystemHealth $read): int
    {
        $this->line($read()->toJson());

        return self::SUCCESS;
    }
}
