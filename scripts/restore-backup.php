<?php

declare(strict_types=1);

use Heptapod\Support\Backup\BackupArchive;

// Standalone recovery must work before Laravel or Composer can boot.
require_once __DIR__.'/../app/Support/Backup/BackupArchive.php';

$archive = $argv[1] ?? throw new InvalidArgumentException('Usage: restore-backup.php archive.zip new-directory');
$destination = $argv[2] ?? throw new InvalidArgumentException('An empty, new extraction directory is required.');
$password = getenv('BACKUP_ARCHIVE_PASSWORD');
if (! is_string($password)) {
    throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD is required.');
}
$result = (new BackupArchive)->extract($archive, $destination, $password);
unset($result['files']);
$result['peak_memory_bytes'] = memory_get_peak_usage(true);
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
