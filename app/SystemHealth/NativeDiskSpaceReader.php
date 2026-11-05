<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Contracts\DiskSpaceReader;

final readonly class NativeDiskSpaceReader implements DiskSpaceReader
{
    public function read(string $path): array
    {
        if (! is_dir($path) || ! is_readable($path)) {
            throw new DiskReadException(DiskReadFailure::Missing, $path);
        }
        // A disappearing mount can still fail after the directory check. Turn native warnings into the visible read-error state.
        set_error_handler(static function (int $severity, string $message) use ($path): never {
            throw new DiskReadException(DiskReadFailure::ReadFailed, $path, $message);
        });
        try {
            $free = disk_free_space($path);
            $total = disk_total_space($path);
        } finally {
            restore_error_handler();
        }
        if ($free === false || $total === false || $total <= 0 || $free < 0 || $free > $total) {
            throw new DiskReadException(DiskReadFailure::InvalidCapacity, $path);
        }

        return ['free' => (int) $free, 'total' => (int) $total];
    }
}
