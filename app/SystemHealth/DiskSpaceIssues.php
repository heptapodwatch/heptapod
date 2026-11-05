<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Contracts\DiskSpaceReader;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final readonly class DiskSpaceIssues
{
    public function __construct(private DiskSpaceReader $reader) {}

    /** @return list<string> Required local writes, plus PostgreSQL only when visible or explicitly configured. */
    public function paths(): array
    {
        $paths = [storage_path(), '/backups'];
        $backupDisks = config()->array('backup.backup.destination.disks');
        foreach ($backupDisks as $disk) {
            if (is_string($disk) && config('filesystems.disks.'.$disk.'.driver') === 'local') {
                $paths[] = config()->string('filesystems.disks.'.$disk.'.root');
            }
        }
        // Spatie creates its temporary workspace only during a backup. Until then its existing parent owns the write.
        $workspace = config()->string('backup.backup.temporary_directory');
        while (! file_exists($workspace) && dirname($workspace) !== $workspace) {
            $workspace = dirname($workspace);
        }
        $paths[] = $workspace;
        $postgres = config('heptapod.system_issues.postgres_data_path');
        if (is_string($postgres) && $postgres !== '') {
            $paths[] = $postgres;
        } elseif (is_dir('/var/lib/postgresql/data')) {
            $paths[] = '/var/lib/postgresql/data';
        }

        return array_values(array_unique($paths));
    }

    /** @return array<string, int|string|null>|null */
    public function detect(string $path): ?array
    {
        try {
            $space = $this->reader->read($path);
        } catch (RuntimeException $exception) {
            // Only the typed reason reaches UI and mail; any other read failure is the generic one, and the message stays in the log.
            $reason = $exception instanceof DiskReadException ? $exception->reason : DiskReadFailure::ReadFailed;
            Log::warning('Filesystem capacity unreadable.', ['path' => $path, 'reason' => $reason->value, 'error' => $exception->getMessage()]);

            return ['path' => $path, 'severity' => 'unreadable', 'reason' => $reason->value];
        }
        $percent = 100 * $space['free'] / $space['total'];
        if ($percent >= 20) {
            return null;
        }

        return ['path' => $path, 'severity' => $percent < 10 ? 'critical' : 'warning',
            'free_percent' => number_format($percent, 2, '.', ''), 'free_bytes' => $space['free'], 'total_bytes' => $space['total']];
    }
}
