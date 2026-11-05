<?php

declare(strict_types=1);

namespace Heptapod\Support\Backup;

use RuntimeException;
use ZipArchive;

/** Also loaded directly by the standalone recovery script, before Laravel can boot. */
final class BackupArchive
{
    /** @return array{sql_files: int, decrypted_sql_bytes: int, uncompressed_bytes: int, files: array<string, array{size: int, sha256: string}>} */
    public function extract(string $archive, string $destination, #[\SensitiveParameter] string $password): array
    {
        if ($password === '') {
            throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD is required.');
        }
        if (! is_file($archive) || ! is_readable($archive)) {
            throw new RuntimeException('The backup archive must be a readable file.');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('The extraction directory must not already exist.');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true || ! $zip->setPassword($password)) {
            throw new RuntimeException('Cannot open the backup archive.');
        }
        try {
            $entries = [];
            $uncompressed = 0;
            $sqlFiles = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    throw new RuntimeException('Cannot read a backup entry.');
                }
                $name = $entry['name'];
                // Do not let archive paths escape, alias each other, or become symlinks.
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")
                    || str_contains($name, '//') || preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $name) === 1
                    || isset($entries[$name])) {
                    throw new RuntimeException('The backup contains an unsafe or duplicate entry path.');
                }
                $operations = 0;
                $attributes = 0;
                if (! $zip->getExternalAttributesIndex($index, $operations, $attributes)
                    || ! is_int($attributes) || (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('The backup contains an unreadable entry or symbolic link.');
                }
                $entries[$name] = $entry;
                $uncompressed += $entry['size'];
                if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                    if ($entry['size'] === 0) {
                        throw new RuntimeException('The SQL dump is empty.');
                    }
                    $sqlFiles++;
                }
            }
            if ($sqlFiles === 0) {
                throw new RuntimeException('The backup must contain a SQL dump.');
            }
            $ancestor = dirname($destination);
            while (! is_dir($ancestor)) {
                $ancestor = dirname($ancestor);
            }
            $free = disk_free_space($ancestor);
            if ($free === false || $free < 2.5 * $uncompressed) {
                throw new \LengthException('Insufficient disk space: at least 2.5 times the uncompressed archive size is required.');
            }
            if (! mkdir($destination, 0700, true)) {
                throw new RuntimeException('Cannot create the extraction directory.');
            }
            $files = [];
            $sqlBytes = 0;
            foreach ($entries as $name => $entry) {
                $path = $destination.'/'.$name;
                $directory = str_ends_with($name, '/') ? rtrim($path, '/') : dirname($path);
                if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                    throw new RuntimeException('Cannot create a backup entry directory.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $input = $zip->getStream($name);
                if ($input === false) {
                    throw new RuntimeException('Cannot decrypt a backup entry.');
                }
                $output = fopen($path, 'xb');
                if ($output === false || ! chmod($path, 0600)) {
                    throw new RuntimeException('Cannot create a backup entry file.');
                }
                try {
                    $bytes = stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                // WinZip AES AE-2 omits CRC (notably for short files); libzip verifies its authentication tag.
                $authenticatedWithoutCrc = $entry['crc'] === 0 && in_array($entry['encryption_method'], [ZipArchive::EM_AES_128, ZipArchive::EM_AES_192, ZipArchive::EM_AES_256], true);
                if ($bytes !== $entry['size'] || (! $authenticatedWithoutCrc && hash_file('crc32b', $path) !== sprintf('%08x', $entry['crc']))) {
                    throw new RuntimeException('A backup entry is incomplete or has an invalid checksum.');
                }
                $sha256 = hash_file('sha256', $path);
                if ($sha256 === false) {
                    throw new RuntimeException('Cannot checksum a backup entry.');
                }
                $files[$name] = ['size' => $bytes, 'sha256' => $sha256];
                if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                    $sqlBytes += $bytes;
                }
            }

            return ['sql_files' => $sqlFiles, 'decrypted_sql_bytes' => $sqlBytes, 'uncompressed_bytes' => $uncompressed, 'files' => $files];
        } finally {
            $zip->close();
        }
    }
}
