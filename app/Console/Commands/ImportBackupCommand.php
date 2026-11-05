<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Closure;
use Heptapod\Import\BackupImport;
use Heptapod\Import\ImportFailure;
use Heptapod\Import\LegacySourceMap;
use Heptapod\Import\SqlDump;
use Heptapod\Support\Backup\BackupArchive;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * One-time offline verification intentionally scans complete tables, unlike runtime queries.
 * PostgreSQL statements are limited to 30 minutes; workers stay stopped and reset is operator-only.
 */
#[Signature('heptapod:'.LegacySourceMap::ImportName.' {backup? : Backup ZIP readable inside the app container} {--dry-run : Validate without restoring the database or copying storage} {--report= : New JSON report path} {--print-env-map : Print environment key names only}')]
final class ImportBackupCommand extends Command
{
    /** @var array<string, mixed> */
    private array $report = [];

    /** @var array<string, float> */
    private array $durations = [];

    private int $failureCode = 2;

    public function getDescription(): string
    {
        return __('Import a historical backup into an empty database; leave maintenance mode on');
    }

    public function handle(BackupImport $import, BackupArchive $archive, SqlDump $dump, Filesystem $filesystem): int
    {
        $this->report = [];
        $this->durations = [];
        $this->failureCode = 2;
        if ($this->option('print-env-map')) {
            $this->table([__('Source key'), __('Target key / action')], array_map(static fn (string $key, string $target): array => [$key, __($target)], array_keys(LegacySourceMap::Environment), LegacySourceMap::Environment));
            $this->line(__('Set APP_NAME manually. Explicitly copy APP_KEY and BACKUP_ARCHIVE_PASSWORD unchanged; review MAIL_* separately. Generate a new DB_PASSWORD and new Reverb keys. No values are read or copied.'));
            $this->line(__('Disable startup migrations before starting the empty target. Keep workers stopped until review.'));

            return self::SUCCESS;
        }
        $backup = $this->argument('backup');
        if (! is_string($backup) || $backup === '') {
            $this->error(__('A backup ZIP path is required.'));

            return 2;
        }
        try {
            $this->step('preconditions', 2, $import->preflight(...));
        } catch (ImportFailure $exception) {
            $this->error(__($exception->getMessage()));

            return 2;
        }
        $run = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
        $root = Storage::disk('local')->path(LegacySourceMap::ImportName);
        $extraction = $root.'/'.$run;
        $reportOption = $this->option('report');
        $reportPath = is_string($reportOption) && $reportOption !== '' ? $reportOption : $root.'/report-'.$run.'.json';
        $reportCreated = false;
        $success = false;
        $exit = 2;
        $this->report = ['started_at' => gmdate(DATE_ATOM), 'dry_run' => $this->option('dry-run'), 'mandatory_tables' => BackupImport::RequiredTables, 'extraction_directory' => $extraction, 'maintenance_mode' => 'on', 'reset_policy' => 'Operator-only reset after post-restore failures; never resume a dirty database. psql --single-transaction --file=- rolls back a failed restore, leaving the initially empty target empty for retry.', 'query_policy' => 'Approved offline import exception: exact whole-table counts and namespace scans; not a runtime query path.', 'statement_timeout_ms' => BackupImport::StatementTimeoutMilliseconds, 'decryption_policy' => 'Probe the lowest-id non-null value per encrypted column, including Fortify 2FA. Individual empty columns are n/a; fail if any probe fails or if every column is n/a.'];
        try {
            // The excluded import directory holds the default report; the guard requires an existing parent.
            if (! is_dir($root) && ! mkdir($root, 0700, true)) {
                throw new ImportFailure('Cannot create the report directory.', 2);
            }
            $import->assertReportOutsideStorage($reportPath);
            if (file_exists($reportPath) || is_link($reportPath)) {
                throw new ImportFailure('The report path must be new and must not overwrite the archive.', 2);
            }
            // Reserve the report before restoring; a bad output path must not leave a restored database without a report.
            $reportHandle = fopen($reportPath, 'xb');
            if ($reportHandle === false || ! chmod($reportPath, 0600)) {
                throw new ImportFailure('Cannot reserve the report file.', 2);
            }
            fclose($reportHandle);
            $reportCreated = true;
            $this->failureCode = 3;
            $hash = is_file($backup) ? hash_file('sha256', $backup) : false;
            if ($hash === false) {
                throw new ImportFailure('The backup archive must be a readable file.', 3);
            }
            $this->report['source_archive_sha256'] = $hash;
            $password = config('backup.backup.password');
            if (! is_string($password) || $password === '') {
                throw new ImportFailure('BACKUP_ARCHIVE_PASSWORD is required.', 3);
            }
            $extracted = $this->step('extract', 3, function () use ($archive, $backup, $extraction, $password): array {
                try {
                    return $archive->extract($backup, $extraction, $password);
                } catch (\LengthException $exception) {
                    throw new ImportFailure($exception->getMessage(), 2, $exception);
                } catch (\RuntimeException $exception) {
                    throw new ImportFailure($exception->getMessage(), 3, $exception);
                }
            });
            if ($extracted['sql_files'] !== 1) {
                throw new ImportFailure('The backup must contain exactly one non-empty SQL dump.', 3);
            }
            $sqlPaths = array_keys(array_filter($extracted['files'], static fn (array $entry, string $path): bool => str_starts_with($path, 'db-dumps/') && str_ends_with($path, '.sql'), ARRAY_FILTER_USE_BOTH));
            $sqlPath = $extraction.'/'.$sqlPaths[0];
            $source = $this->step('manifest', 3, fn (): array => $dump->manifest($sqlPath));
            $this->report['source_dump'] = ['path' => $sqlPaths[0], 'database' => preg_replace('/^postgresql-/', '', pathinfo($sqlPaths[0], PATHINFO_FILENAME)), 'header' => $source['header'], 'highest_migration' => $source['highest_migration'], 'tables' => $source['tables'], 'class_names' => $source['class_names'], 'brand_rows' => $source['brand_rows'], 'filtered_statements' => $source['filtered_statements']];
            $import->assertMigrations($source['migrations']);
            foreach (BackupImport::RequiredTables as $table) {
                if (! array_key_exists($table, $source['tables'])) {
                    throw new ImportFailure(__('The source is missing a mandatory table: :table', ['table' => $table]), 3);
                }
            }
            $files = $import->storageManifest($extracted['files']);
            $this->report['storage_manifest'] = $files;
            $this->report['storage_skipped'] = $import->skippedStorageEntries($extracted['files']);
            $this->report['uncompressed_bytes'] = $extracted['uncompressed_bytes'];
            $this->step('storage_preflight', 2, fn () => $import->assertStorageTarget($files));
            // Environment inspection is key-only; never expose values in stdout or the report.
            $envKeys = array_keys(array_merge($_SERVER, $_ENV));
            $envFile = app()->environmentFilePath();
            if (is_readable($envFile)) {
                foreach (new \SplFileObject($envFile) as $line) {
                    if (is_string($line) && preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $matches) === 1) {
                        $envKeys[] = $matches[1];
                    }
                }
            }
            $this->report['source_environment_keys_present'] = array_values(array_intersect(array_keys(LegacySourceMap::Environment), $envKeys));
            if (! $this->option('dry-run')) {
                $this->step('restore', 4, fn () => $import->restore($dump, $sqlPath, $extraction.'/restore.log'));
                $settings = $this->step('migrate', 5, fn (): array => $import->migrateRecordingSettings(function () use ($extraction): void {
                    $output = new BufferedOutput;
                    try {
                        $exitCode = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true], $output);
                    } catch (Throwable $exception) {
                        if (str_starts_with($exception->getMessage(), 'Stored class names without a morph alias:')) {
                            throw new ImportFailure('Unknown source class names blocked the morph migration. Inspect private migration diagnostics and reset the target before retrying.', 6, $exception);
                        }
                        throw $exception;
                    } finally {
                        $log = $extraction.'/migrate.log';
                        if (file_put_contents($log, $output->fetch()) === false || ! chmod($log, 0600)) {
                            throw new ImportFailure('Cannot write private migration diagnostics.', 5);
                        }
                    }
                    if ($exitCode !== 0) {
                        throw new ImportFailure('Migrations failed. Inspect the private migration log and reset the target before retrying.', 5);
                    }
                }));
                /** @var list<string> $applied */
                $applied = $import->connection()->table('migrations')->orderBy('id')->pluck('migration')->all();
                $applied = array_values(array_diff($applied, $source['migrations']));
                $this->report['migrations_applied'] = $applied;
                // Checked right after migrate: later import steps (the class-name rewrite) have their own report.
                $settingsVerification = $import->settingsVerification($settings, $applied);
                $this->report['settings_migrations'] = $settingsVerification;
                if ($settingsVerification['mismatches'] !== []) {
                    throw new ImportFailure('Target settings differ from the source beyond what this import\'s settings migrations wrote. See report settings_migrations.mismatches.', 7);
                }
                $dropped = $this->step('drop_transient', 6, fn (): array => $import->dropTransientData($root.'/failed-jobs-'.$run.'.jsonl'));
                $this->report['dropped_tables'] = $dropped;
                if (($dropped['failed_jobs'] ?? 0) > 0) {
                    $this->report['failed_jobs_export'] = $root.'/failed-jobs-'.$run.'.jsonl';
                }
                $this->report['class_name_rewrites'] = $this->step('class_name_rewrite', 6, $import->rewriteLegacyClassNames(...));
                $scan = $this->step('residual_scan', 6, fn (): array => $import->scan(function (string $table, int $rows, float $elapsed): void {
                    $this->line(__('Scan :table: :rows rows, :seconds s', ['table' => $table, 'rows' => $rows, 'seconds' => number_format($elapsed, 3, '.', '')]));
                }));
                $this->report['residual_scan'] = $scan;
                if ($scan['class_names'] !== []) {
                    throw new ImportFailure('Unknown source class names remain. See report table/column/count/row locators; no values are logged. Reset the target before retrying.', 6);
                }
                $this->report['storage_copied'] = $this->step('storage_copy', 9, fn (): array => $import->copyStorage($extraction, $files));
                $media = $this->step('media_verification', 9, $import->verifyMedia(...));
                $this->report['media'] = $media;
                if ($media['missing_media_ids'] !== [] || $media['missing_site_ids'] !== []) {
                    throw new ImportFailure('Media or site icon files are missing or have the wrong size. See report row IDs.', 9);
                }
                $counts = $this->step('count_verification', 7, fn (): array => $import->counts($source['tables'], $applied, count($settingsVerification['added']), function (string $table, int $rows, float $elapsed): void {
                    $this->line(__('Count :table: :rows rows, :seconds s', ['table' => $table, 'rows' => $rows, 'seconds' => number_format($elapsed, 3, '.', '')]));
                }));
                $this->report['counts'] = $counts;
                if (array_any($counts, static fn (array $row): bool => $row['expected'] !== $row['target'])) {
                    throw new ImportFailure('Source and target table counts do not match. See report.', 7);
                }
                $sequences = $this->step('sequence_verification', 7, $import->sequences(...));
                $this->report['sequences'] = $sequences;
                if (array_any($sequences, static fn (array $row): bool => ! $row['ok'])) {
                    throw new ImportFailure('A restored sequence is behind its table IDs. See report.', 7);
                }
                $decryption = $this->step('decryption', 8, $import->decryption(...));
                $this->report['decryption'] = $decryption;
                if (array_all($decryption, static fn (array $row): bool => $row['result'] === 'n/a')) {
                    throw new ImportFailure('No encrypted values could be probed. APP_KEY cannot be verified; reset the target before retrying with a verifiable source backup.', 8);
                }
                if (array_any($decryption, static fn (array $row): bool => $row['result'] === 'fail')) {
                    throw new ImportFailure('APP_KEY does not match the source. Reset the target before retrying with the source key.', 8);
                }
                $this->report['instance_settings'] = $import->connection()->table('settings')->where('group', 'instance')->whereIn('name', ['name', 'timezone', 'updates_enabled'])->pluck('payload', 'name')->all();
                $this->step('optimize_clear', 7, function (): void {
                    // A broad cache flush also deletes cache-backed production maintenance mode.
                    foreach (['optimize:clear' => ['--except' => 'cache'], 'settings:clear-cache' => [], 'permission:cache-reset' => []] as $command => $arguments) {
                        if (Artisan::call($command, $arguments + ['--no-interaction' => true], new BufferedOutput) !== 0) {
                            throw new ImportFailure('Cache cleanup failed. Keep maintenance mode on and inspect the target.', 7);
                        }
                    }
                    if (! app()->maintenanceMode()->active()) {
                        throw new ImportFailure('Maintenance mode changed unexpectedly. Run artisan down immediately and inspect the target.', 7);
                    }
                });
            }
            $success = true;
            $exit = 0;
        } catch (Throwable $exception) {
            $exit = $exception instanceof ImportFailure ? $exception->getCode() : $this->failureCode;
            // Framework/SQL exception messages may contain values. Keep the boundary report safe.
            $message = $exception instanceof ImportFailure ? $exception->getMessage() : __('Import step failed (:exception). Inspect private target logs; keep maintenance mode on.', ['exception' => $exception::class]);
            $this->report['error'] = $message;
            if ((! $exception instanceof ImportFailure || $exception->getPrevious() instanceof Throwable) && is_dir($extraction)) {
                $privateLog = $extraction.'/exception.log';
                try {
                    if (file_put_contents($privateLog, (string) $exception) === false || ! chmod($privateLog, 0600)) {
                        throw new ImportFailure('Cannot retain private failure diagnostics.', 9, $exception);
                    }
                } catch (Throwable) {
                    $exit = 9;
                    $this->report['diagnostic_error'] = 'Cannot retain private failure diagnostics.';
                    $this->error(__('Cannot retain private failure diagnostics.'));
                }
            }
            $this->error(__($message));
        } finally {
            if ($success && is_dir($extraction)) {
                try {
                    if (! $filesystem->deleteDirectory($extraction)) {
                        throw new ImportFailure('Import succeeded but private extraction cleanup failed.', 9);
                    }
                } catch (Throwable) {
                    $success = false;
                    $exit = 9;
                    $this->report['error'] = 'Import succeeded but private extraction cleanup failed.';
                    $this->error(__('Import succeeded but private extraction cleanup failed.'));
                }
            }
            $this->report['exit_code'] = $exit;
            $this->report['durations_seconds'] = $this->durations;
            $this->report['finished_at'] = gmdate(DATE_ATOM);
            if (! $success && is_dir($extraction)) {
                $this->report['extraction_cleanup_command'] = 'rm -rf -- '.escapeshellarg($extraction);
                $this->warn(__('Decrypted source retained at :path. Remove after inspection: :command', ['path' => $extraction, 'command' => 'rm -rf -- '.escapeshellarg($extraction)]));
            }
            if ($reportCreated) {
                try {
                    $json = json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                    if (file_put_contents($reportPath, $json) !== strlen($json)) {
                        throw new ImportFailure('Cannot write the import report. Keep maintenance mode on.', 9);
                    }
                    $this->line(__('Report: :path', ['path' => $reportPath]));
                } catch (Throwable) {
                    $success = false;
                    $exit = 9;
                    $this->error(__('Cannot write the import report. Keep maintenance mode on.'));
                }
            }
        }
        if ($success) {
            $this->info($this->option('dry-run')
                ? __('Dry run passed. No database rows or target storage files were written.')
                : __('Import passed: table counts, sequences, storage and decryption verified. Maintenance mode remains on.'));
        }

        return $exit;
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function step(string $name, int $failureCode, Closure $operation): mixed
    {
        $this->failureCode = $failureCode;
        $start = microtime(true);
        try {
            return $operation();
        } finally {
            $this->durations[$name] = round(microtime(true) - $start, 3);
        }
    }
}
