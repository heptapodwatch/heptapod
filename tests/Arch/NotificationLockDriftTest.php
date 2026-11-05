<?php

use Tests\Arch\NotificationLockDrift;

// The notification engine takes row locks only through NotificationLocks, so its one hierarchy cannot drift.
test('the notification engine takes row locks only through NotificationLocks', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (NotificationLockDrift::Roots as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || $relative === NotificationLockDrift::Hierarchy) {
                continue;
            }
            foreach (NotificationLockDrift::occurrences($file->getPathname()) as $lock) {
                $lines = implode(',', $lock['lines']);
                expect($lock['count'])->toBeLessThanOrEqual(NotificationLockDrift::allowedCount($relative, $lock['method'], $lock['lock']),
                    "{$relative}:{$lines} {$lock['method']}() takes {$lock['lock']} directly {$lock['count']} times; lock through NotificationLocks.");
            }
        }
    }
});

// The list may only shrink: an entry whose lock is gone (or occurs less often) must be updated with the fix.
test('every notification lock drift entry still matches its direct locks', function (array $entry): void {
    $found = NotificationLockDrift::occurrences(dirname(__DIR__, 2).'/'.$entry['file'])[$entry['method'].' '.$entry['lock']]['count'] ?? 0;

    expect($found)->toBe($entry['count'], "Stale lock drift entry {$entry['file']} {$entry['method']}() {$entry['lock']}: update its count in NotificationLockDrift::Allowed.")
        ->and($entry['reason'])->not->toBe('');
})->with(array_map(static fn (array $entry): array => [$entry], NotificationLockDrift::Allowed));
