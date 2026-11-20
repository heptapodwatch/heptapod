<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

final readonly class BackupHistoryReader
{
    public function __construct(private ListPaging $paging) {}

    /** @return array<string, mixed> */
    public function read(?User $actor = null): array
    {
        $disk = config()->string('backup.backup.destination.disks.0');
        $name = config()->string('backup.backup.name');
        $destination = BackupDestination::create($disk, $name);
        $reachable = $destination->isReachable();
        $path = route('settings.updates.edit');

        $allArchives = $reachable ? array_values(BackupCollection::createFromFiles(
            $destination->disk(),
            $destination->disk()->allFiles($name),
        )->toBase()->map(static fn (Backup $backup): array => [
            'created_at' => $backup->date()->toIso8601String(),
            'size_bytes' => $backup->sizeInBytes(),
        ])->all()) : [];
        $archivesPaginator = $this->paging->paginateList($allArchives, 'backup_archives')->withPath($path);

        $columns = ['id', 'requested_by', 'state', 'disk', 'http_status', 'requested_at', 'started_at', 'completed_at'];
        $runsPaginator = $this->paging->paginate(
            DB::table('backup_runs')->orderByDesc('id')->select($columns),
            'backup_runs',
        )->withPath($path);
        $runs = collect($runsPaginator->items())->map($this->formatRun(...))->values()->all();
        $latestRow = $runsPaginator->currentPage() === 1
            ? null
            : DB::table('backup_runs')->orderByDesc('id')->first($columns);
        $latestRun = $runsPaginator->currentPage() === 1
            ? ($runs[0] ?? null)
            : ($latestRow === null ? null : $this->formatRun($latestRow));
        $hasActiveRun = DB::table('backup_runs')->whereIn('state', ['queued', 'running'])->exists();
        // Matches ThrottleRequests's default hashed limiter key for the named `backup-now` limiter.
        $cooldownSeconds = $actor instanceof User ? RateLimiter::availableIn(hash('md5', 'backup-now'.$actor->id)) : 0;

        return [
            'disk' => $disk,
            'reachable' => $reachable,
            'archives' => $archivesPaginator->items(),
            'archives_pagination' => PaginationData::fromPaginator($archivesPaginator),
            'runs' => $runs,
            'runs_pagination' => PaginationData::fromPaginator($runsPaginator),
            'latest_run' => $latestRun,
            'has_active_run' => $hasActiveRun,
            'schedule_utc' => '02:30',
            'retention' => config()->array('backup.cleanup.default_strategy'),
            'uses_default_retention' => config('backup.cleanup.strategy') === DefaultStrategy::class,
            'cooldown_until' => $cooldownSeconds > 0 ? now('UTC')->addSeconds($cooldownSeconds)->toIso8601String() : null,
            'observed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, requested_by: int|null, state: string, disk: string|null, http_status: int|null, requested_at: string, started_at: string|null, completed_at: string|null}
     */
    private function formatRun(mixed $run): array
    {
        /** @var \stdClass&object{id: int, requested_by: int|null, state: string, disk: string|null, http_status: int|null, requested_at: string, started_at: string|null, completed_at: string|null} $run */
        return [
            'id' => $run->id,
            'requested_by' => $run->requested_by,
            'state' => $run->state,
            'disk' => $run->disk,
            'http_status' => $run->http_status,
            'requested_at' => CarbonImmutable::parse($run->requested_at, 'UTC')->toIso8601String(),
            'started_at' => $run->started_at === null ? null : CarbonImmutable::parse($run->started_at, 'UTC')->toIso8601String(),
            'completed_at' => $run->completed_at === null ? null : CarbonImmutable::parse($run->completed_at, 'UTC')->toIso8601String(),
        ];
    }
}
