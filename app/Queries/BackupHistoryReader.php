<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

final class BackupHistoryReader
{
    /** @return array<string, mixed> */
    public function read(): array
    {
        $disk = config()->string('backup.backup.destination.disks.0');
        $name = config()->string('backup.backup.name');
        $destination = BackupDestination::create($disk, $name);
        $reachable = $destination->isReachable();
        $archives = $reachable ? BackupCollection::createFromFiles($destination->disk(),
            $destination->disk()->allFiles($name))->take(20)->map(static fn (Backup $backup): array => [
                'created_at' => $backup->date()->toIso8601String(), 'size_bytes' => $backup->sizeInBytes(),
            ])->values()->all() : [];
        $runs = DB::table('backup_runs')->orderByDesc('id')->limit(20)->get([
            'id', 'requested_by', 'state', 'disk', 'http_status', 'requested_at', 'started_at', 'completed_at',
        ])->map(static function (\stdClass $run): array {
            /** @var \stdClass&object{requested_at: string, started_at: string|null, completed_at: string|null} $run */
            return [...(array) $run,
                'requested_at' => CarbonImmutable::parse($run->requested_at, 'UTC')->toIso8601String(),
                'started_at' => $run->started_at === null ? null : CarbonImmutable::parse($run->started_at, 'UTC')->toIso8601String(),
                'completed_at' => $run->completed_at === null ? null : CarbonImmutable::parse($run->completed_at, 'UTC')->toIso8601String(),
            ];
        })->all();

        return ['disk' => $disk, 'reachable' => $reachable, 'archives' => $archives,
            'runs' => $runs, 'schedule_utc' => '02:30', 'retention' => config()->array('backup.cleanup.default_strategy'),
            'uses_default_retention' => config('backup.cleanup.strategy') === DefaultStrategy::class,
            'observed_at' => now()->toIso8601String()];
    }
}
