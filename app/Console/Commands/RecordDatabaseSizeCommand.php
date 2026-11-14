<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\Actions\RecordDatabaseSize;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Scheduled hourly: the first run of a UTC day records its sample, so a new install has one within the hour. */
#[Description('Record today\'s database size sample for the growth projection on System health')]
#[Signature('heptapod:record-database-size')]
final class RecordDatabaseSizeCommand extends Command
{
    public function handle(RecordDatabaseSize $record): int
    {
        $bytes = $record(CarbonImmutable::now('UTC'));
        $this->line($bytes === null ? 'Today\'s database size sample already exists.' : 'Recorded database size sample: '.$bytes.' bytes.');

        return self::SUCCESS;
    }
}
