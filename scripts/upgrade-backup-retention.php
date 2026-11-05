<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

// Runs inside the app container over stdin. Host lifecycle metadata is never mounted into the app.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1];
$root = '/backups/upgrade-lifecycle';
$keep = config('heptapod.upgrade_backup_keep');
if (! is_int($keep) || $keep < 1) {
    throw new RuntimeException('HEPTAPOD_UPGRADE_BACKUP_KEEP must be a positive integer.');
}

$verify = static function (string $path, string $checksum): void {
    if (! str_starts_with($path, '/backups/') || str_contains($path, '/../') || str_contains($path, '/./')
        || preg_match('/[\r\n\t|]/', $path) === 1 || is_link($path)
        || ! is_file($path) || ! is_readable($path) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
        || hash_file('sha256', $path) !== $checksum) {
        throw new RuntimeException('Recorded lifecycle archive is missing, unsafe or has a checksum mismatch: '.$path);
    }
    $real = realpath($path);
    if ($real !== $path) {
        throw new RuntimeException('Lifecycle archive paths must be canonical and must not traverse symlinks: '.$path);
    }
};

if ($mode === 'retire') {
    [$old, $checksum, $new] = array_slice($argv, 2);
    $verify($old, $checksum);
    $verify($new, $checksum);
    if (! unlink($old)) {
        throw new RuntimeException('Cannot remove relocated legacy archive: '.$old);
    }
    echo 'MOVED '.$old.' -> '.$new.PHP_EOL;
    exit(0);
}

$references = [];
$migrations = [];
foreach (['current' => 2, 'previous' => 4] as $generation => $index) {
    $path = $argv[$index];
    $checksum = $argv[$index + 1];
    if ($path === '') {
        continue;
    }
    $verify($path, $checksum);
    $target = $path;
    if (! str_starts_with($path, $root.'/')) {
        if (preg_match('~^/backups/([^/]+)/(pre-upgrade-[a-f0-9]{7,40}-[0-9]{8}T[0-9]{6}Z\.zip)$~D', $path, $match) !== 1) {
            throw new RuntimeException('Unrecognized legacy lifecycle archive path: '.$path);
        }
        $target = $root.'/'.$match[1].'/'.$match[2];
        $migrations[] = ['generation' => $generation, 'old' => $path, 'new' => $target, 'checksum' => $checksum];
    }
    $references[$target][] = $generation.' generation reference';
}

if ($mode === 'stage') {
    foreach ($migrations as $migration) {
        $target = $migration['new'];
        if (file_exists($target)) {
            $verify($target, $migration['checksum']);

            continue;
        }
        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create lifecycle archive directory: '.$directory);
        }
        // Never follow a namespace symlink out of the dedicated directory.
        if (realpath($directory) !== $directory) {
            throw new RuntimeException('Unsafe lifecycle archive directory: '.$directory);
        }
        $temporary = $target.'.relocating';
        if (! copy($migration['old'], $temporary)) {
            throw new RuntimeException('Cannot stage lifecycle archive: '.$target);
        }
        $verify($temporary, $migration['checksum']);
        $handle = fopen($temporary, 'rb');
        if ($handle === false || ! fsync($handle)) {
            throw new RuntimeException('Cannot flush lifecycle archive: '.$temporary);
        }
        fclose($handle);
        if (! rename($temporary, $target)) {
            throw new RuntimeException('Cannot publish relocated lifecycle archive: '.$target);
        }
        echo 'STAGED '.$migration['old'].' -> '.$target.PHP_EOL;
    }
    exit(0);
}
if (! in_array($mode, ['plan', 'apply'], true)) {
    throw new RuntimeException('Unknown lifecycle retention mode.');
}
if ($mode === 'apply' && $migrations !== []) {
    throw new RuntimeException('Relocate legacy references before expiry.');
}

$archives = [];
if (is_dir($root)) {
    if (realpath($root) !== $root) {
        throw new RuntimeException('Unsafe lifecycle backup root.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
            continue;
        }
        if (preg_match('/^pre-upgrade-[a-f0-9]{7,40}-([0-9]{8}T[0-9]{6}Z)\.zip$/D', $file->getFilename(), $match) === 1) {
            $archives[$file->getPathname()] = $match[1];
        }
    }
}
foreach ($migrations as $migration) {
    preg_match('/-([0-9]{8}T[0-9]{6}Z)\.zip$/D', $migration['new'], $match);
    $archives[$migration['new']] = $match[1];
}
uksort($archives, static fn (string $a, string $b): int => strcmp($archives[$b], $archives[$a]) ?: strcmp($a, $b));
$entries = [];
foreach (array_keys($archives) as $index => $path) {
    $reasons = $references[$path] ?? [];
    if ($index < $keep) {
        $reasons[] = 'newest '.$keep.' lifecycle archives';
    }
    $delete = $reasons === [];
    $entries[] = ['path' => $path, 'action' => $delete ? 'DELETE' : 'KEEP', 'reason' => $delete ? 'outside newest '.$keep.' and not referenced by current or previous generation' : implode('; ', $reasons)];
}
if ($mode === 'plan') {
    echo json_encode(['migrations' => $migrations, 'archives' => $entries], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}
foreach ($entries as $entry) {
    if ($entry['action'] !== 'DELETE') {
        continue;
    }
    if (! unlink($entry['path'])) {
        throw new RuntimeException('Cannot delete lifecycle archive: '.$entry['path']);
    }
    echo 'DELETED '.$entry['path'].' — '.$entry['reason'].PHP_EOL;
}
