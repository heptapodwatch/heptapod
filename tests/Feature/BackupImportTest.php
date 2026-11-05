<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Import\BackupImport;
use Heptapod\Import\ImportFailure;
use Heptapod\Import\LegacySourceMap;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
});

it('names the conflicting disk and path without modifying target storage', function (string $diskName, string $prefix): void {
    Storage::fake('local');
    Storage::fake('public');
    $disk = Storage::disk($diskName);
    $disk->put('media/logo.png', 'target file');
    $files = [$prefix.'/media/logo.png' => ['disk' => $diskName, 'path' => 'media/logo.png', 'size' => 11, 'sha256' => hash('sha256', 'source file')]];

    expect(fn () => app(BackupImport::class)->assertStorageTarget($files))->toThrow(ImportFailure::class,
        'Target storage contains a non-identical file: disk "'.$diskName.'", path "media/logo.png". Use an empty storage volume or remove the conflict deliberately.');

    expect($disk->get('media/logo.png'))->toBe('target file');
})->with(['private' => ['local', 'private'], 'public' => ['public', 'public']]);

it('refuses a report inside scanned private storage before reserving a file', function (bool $throughSymlink): void {
    $disk = Storage::fake('local');
    Storage::fake('public');
    $disk->makeDirectory(LegacySourceMap::ImportName);
    $link = $disk->path(LegacySourceMap::ImportName.'/alias');
    if ($throughSymlink) {
        expect(symlink($disk->path(''), $link))->toBeTrue();
    }
    $path = $throughSymlink ? $link.'/report.json' : $disk->path('report.json');

    try {
        expect(fn () => app(BackupImport::class)->assertReportOutsideStorage($path))->toThrow(ImportFailure::class,
            'The report path '.$path.' is inside the storage disk "local" that the import verifies. Write the report outside storage or below '.$disk->path(LegacySourceMap::ImportName).'.');
        expect(file_exists($path))->toBeFalse();
    } finally {
        if ($throughSymlink) {
            unlink($link);
        }
    }
})->with(['direct path' => false, 'symlink from excluded import directory' => true]);

it('allows reports outside storage and excludes reserved import reports from the scan', function (bool $insideImport): void {
    $disk = Storage::fake('local');
    Storage::fake('public');
    $outside = Storage::fake('import-report-outside');
    $disk->makeDirectory(LegacySourceMap::ImportName);
    $path = $insideImport ? $disk->path(LegacySourceMap::ImportName.'/report.json') : $outside->path('report.json');

    app(BackupImport::class)->assertReportOutsideStorage($path);
    file_put_contents($path, 'reserved report');
    app(BackupImport::class)->assertStorageTarget([]);

    expect(file_get_contents($path))->toBe('reserved report');
})->with(['excluded import directory' => true, 'outside scanned disks' => false]);
