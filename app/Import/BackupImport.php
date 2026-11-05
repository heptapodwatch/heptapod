<?php

declare(strict_types=1);

namespace Heptapod\Import;

use Closure;
use Heptapod\Models\Check;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Spatie\LaravelSettings\Migrations\SettingsMigration;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Offline, one-shot import: exact whole-table counts and scans deliberately bypass runtime pagination.
 * Each PostgreSQL statement has a 30-minute limit; production-volume timing belongs to the rehearsal.
 */
final readonly class BackupImport
{
    public const int StatementTimeoutMilliseconds = 1_800_000;

    public const array DroppedTables = ['jobs', 'failed_jobs', 'cache', 'cache_locks', 'sessions'];

    public const array RequiredTables = ['sites', 'checks', 'check_runs', 'incidents', 'events', 'deliveries', 'users', 'personal_access_tokens', 'media', 'settings', 'notification_destinations', 'maintenance_periods', 'cron_checks', 'activity_log'];

    /** @var array<class-string<Model>, list<string>> */
    private const array EncryptedColumns = [
        Check::class => ['config_secrets', 'run_headers'],
        Site::class => ['crawler_headers'],
        NotificationDestination::class => ['config_secrets'],
    ];

    /** Fortify encrypts serialized strings without Eloquent encrypted casts. */
    private const array FortifyEncryptedColumns = ['two_factor_secret', 'two_factor_recovery_codes'];

    public function __construct(private MasterSupervisorRepository $masters) {}

    public function connection(): Connection
    {
        return DB::connection('pgsql');
    }

    public function preflight(): void
    {
        if (! app()->isDownForMaintenance()) {
            throw new ImportFailure('Run artisan down first. The importer never brings the app up.', 2);
        }
        if (config('filesystems.disks.local.driver') !== 'local') {
            throw new ImportFailure('Import scratch and reports require the configured local disk to use the local driver.', 2);
        }
        if (config('database.default') !== 'pgsql') {
            throw new ImportFailure('The default database connection must be pgsql.', 2);
        }
        $this->connection()->statement('SET statement_timeout = '.self::StatementTimeoutMilliseconds);
        if ($this->connection()->getSchemaBuilder()->hasTable('failed_jobs') && $this->connection()->table('failed_jobs')->count() > 0) {
            throw new ImportFailure('Target failed jobs are not empty. Clear them before importing.', 2);
        }
        if ($this->connection()->table('pg_catalog.pg_tables')->where('schemaname', 'public')->count() !== 0) {
            throw new ImportFailure('Target public schema is not empty. Operator reset required: connect to the disposable target with psql and run DROP SCHEMA public CASCADE; CREATE SCHEMA public; then retry. This command never resets data.', 2);
        }
        if ($this->masters->all() !== [] || Cache::store('redis')->has(config()->string('heptapod.health.scheduler_cache_key'))) {
            throw new ImportFailure('Stop Horizon, dispatcher, and scheduler; the target scheduler heartbeat must be absent.', 2);
        }
        // Horizon's named queues and each connection's default include ready, delayed and reserved jobs.
        /** @var array<string, array{connection: string, queue: list<string>}> $supervisors */
        $supervisors = array_replace_recursive(
            config()->array('horizon.defaults'),
            config()->array('horizon.environments.*', []),
            config()->array('horizon.environments.'.app()->environment(), []),
        );
        foreach (config()->array('queue.connections') as $name => $configuration) {
            if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'redis') {
                continue;
            }
            $queue = Queue::connection((string) $name);
            if (! $queue instanceof RedisQueue) {
                throw new ImportFailure('A configured Redis queue has an unsupported driver.', 2);
            }
            $defaultQueue = $configuration['queue'] ?? 'default';
            if (! is_string($defaultQueue)) {
                throw new ImportFailure('A configured Redis queue must name its default queue.', 2);
            }
            $queues = [$defaultQueue];
            foreach ($supervisors as $supervisor) {
                if ($supervisor['connection'] === $name) {
                    array_push($queues, ...$supervisor['queue']);
                }
            }
            foreach (array_unique($queues) as $queueName) {
                if ($queue->size($queueName) > 0) {
                    throw new ImportFailure('Target Redis queues are not empty. Drain them before importing.', 2);
                }
            }
        }
        $this->assertEncryptedRegistry();
    }

    /** @param list<string> $migrations */
    public function assertMigrations(array $migrations): void
    {
        $migrator = app('migrator');
        $known = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);
        $unknown = array_values(array_diff($migrations, array_keys($known)));
        if ($unknown !== []) {
            throw new ImportFailure(__('Source contains migrations unknown to this release: :migrations', ['migrations' => implode(', ', $unknown)]), 3);
        }
    }

    public function restore(SqlDump $dump, string $path, string $diagnosticPath): void
    {
        $process = new Process([
            'psql', '--no-psqlrc', '--no-password', '--single-transaction', '--file=-', '--set=ON_ERROR_STOP=1', '--quiet',
            '--host='.$this->connectionString('host'), '--port='.$this->connectionString('port'),
            '--username='.$this->connectionString('username'), '--dbname='.$this->connectionString('database'),
        ], env: ['PGPASSWORD' => $this->connectionString('password'), 'PGSSLMODE' => $this->connectionString('sslmode'), 'PGOPTIONS' => '-c statement_timeout='.self::StatementTimeoutMilliseconds], timeout: null);
        $process->setInput($dump->portableSql($path, self::StatementTimeoutMilliseconds));
        $diagnostics = fopen($diagnosticPath, 'xb');
        if ($diagnostics === false || ! chmod($diagnosticPath, 0600)) {
            throw new ImportFailure('Cannot create private restore diagnostics.', 4);
        }
        try {
            $exit = $process->run(static function (string $type, string $buffer) use ($diagnostics): void {
                if (fwrite($diagnostics, $buffer) !== strlen($buffer)) {
                    throw new ImportFailure('Cannot write restore diagnostics.', 4);
                }
            });
        } finally {
            fclose($diagnostics);
        }
        if ($exit !== 0) {
            throw new ImportFailure('psql restore failed; inspect the private restore.log. The single-transaction stdin file action rolls back database changes; retry on the empty target.', 4);
        }
    }

    /** @return array<string, int> */
    public function dropTransientData(string $failedJobsPath): array
    {
        $counts = [];
        foreach (self::DroppedTables as $table) {
            if (! $this->connection()->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $counts[$table] = $this->connection()->table($table)->count();
            if ($table === 'failed_jobs' && $counts[$table] > 0) {
                $output = fopen($failedJobsPath, 'xb');
                if ($output === false || ! chmod($failedJobsPath, 0600)) {
                    throw new ImportFailure('Cannot preserve failed jobs before dropping them.', 6);
                }
                try {
                    foreach ($this->connection()->table($table)->orderBy('id')->lazyById(500) as $row) {
                        $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                        if (fwrite($output, $line) !== strlen($line)) {
                            throw new ImportFailure('Cannot write the failed jobs archive.', 6);
                        }
                    }
                } finally {
                    fclose($output);
                }
            }
            $this->connection()->statement('TRUNCATE TABLE '.$this->identifier($table));
        }

        return $counts;
    }

    /**
     * Rewrites renamed legacy class names inside JSON payloads, in one transaction, before the residual scan.
     * Only exact string values that name an existing Heptapod class change; JSON strings that hold JSON
     * (for example incident evidence copying a run error) are rewritten recursively. Anything else stays
     * for the residual scan to report.
     *
     * @return list<array{table: string, column: string, rows: int, classes: array<string, string>}>
     */
    public function rewriteLegacyClassNames(): array
    {
        return $this->connection()->transaction(function (): array {
            /** @var list<object{table_name: string, column_name: string, data_type: string}> $columns */
            $columns = $this->connection()->select("SELECT c.table_name, c.column_name, c.data_type FROM information_schema.columns c JOIN pg_catalog.pg_class t ON t.relname = c.table_name JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace AND n.nspname = c.table_schema WHERE c.table_schema = 'public' AND c.data_type IN ('text','character varying','json','jsonb') AND t.relkind IN ('r','p') AND NOT t.relispartition AND EXISTS (SELECT 1 FROM information_schema.columns k WHERE k.table_schema = 'public' AND k.table_name = c.table_name AND k.column_name = 'id') ORDER BY c.table_name, c.ordinal_position");
            $rewritten = [];
            foreach ($columns as $column) {
                $sqlTable = $this->identifier($column->table_name);
                $sqlColumn = $this->identifier($column->column_name);
                $cast = in_array($column->data_type, ['json', 'jsonb'], true) ? '::'.$column->data_type : '';
                $rows = 0;
                $classes = [];
                $lastId = null;
                do {
                    // Keyset batches over the primary key keep each statement bounded.
                    $query = "SELECT id, {$sqlColumn}::text AS payload FROM {$sqlTable} WHERE {$sqlColumn}::text ~ ?".($lastId === null ? '' : ' AND id > ?').' ORDER BY id LIMIT 500';
                    /** @var list<object{id: int|string, payload: string}> $batch */
                    $batch = $this->connection()->select($query, $lastId === null ? [LegacySourceMap::ClassNamePattern] : [LegacySourceMap::ClassNamePattern, $lastId]);
                    foreach ($batch as $row) {
                        $lastId = $row->id;
                        $rewrittenPayload = $this->rewriteJsonText($row->payload, $classes);
                        if ($rewrittenPayload !== null && $rewrittenPayload !== $row->payload) {
                            $this->connection()->update("UPDATE {$sqlTable} SET {$sqlColumn} = ?{$cast} WHERE id = ?", [$rewrittenPayload, $row->id]);
                            $rows++;
                        }
                    }
                } while (count($batch) === 500);
                if ($rows > 0) {
                    ksort($classes);
                    $rewritten[] = ['table' => $column->table_name, 'column' => $column->column_name, 'rows' => $rows, 'classes' => $classes];
                }
            }

            return $rewritten;
        });
    }

    /**
     * Rewrites JSON string tokens in place; every other byte (key order, spacing, numbers, {} vs []) stays.
     *
     * @param  array<string, string>  $classes  collects legacy => renamed pairs that were applied
     * @return string|null the rewritten JSON text, or null when the text is not a JSON object or array
     */
    private function rewriteJsonText(string $text, array &$classes): ?string
    {
        try {
            $decoded = json_decode($text, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Not JSON: the residual scan reports any legacy name that remains.
            return null;
        }
        if (! is_array($decoded) && ! is_object($decoded)) {
            return null;
        }
        // In valid JSON every '"' outside a string token opens one, so this lexes exactly the string tokens.
        // Object keys (a token followed by ':') are never renamed: a legacy key next to its renamed twin would
        // collapse into a duplicate key. The residual scan reports legacy keys instead.
        $rewritten = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(?=(\s*:)?)/s', function (array $match) use (&$classes): string {
            $token = $match[0];
            if (($match[1] ?? '') !== '') {
                return $token;
            }
            $value = json_decode($token, flags: JSON_THROW_ON_ERROR);
            if (! is_string($value) || ! str_contains($value, LegacySourceMap::NamespacePrefix)) {
                return $token;
            }
            $renamed = $this->renamedClass($value);
            if ($renamed !== null) {
                $classes[$value] = $renamed;

                return $this->encodeJsonString($renamed, $token);
            }
            $nested = $this->rewriteJsonText($value, $classes);

            return $nested === null || $nested === $value ? $token : $this->encodeJsonString($nested, $token);
        }, $text);
        if ($rewritten === null) {
            throw new ImportFailure('Cannot rewrite a JSON payload.', 6);
        }

        return $rewritten;
    }

    /**
     * The renamed class for an exact legacy class name, or null when no class, interface or enum exists under it.
     * Unknown names stay unchanged so the residual scan still fails loudly.
     */
    private function renamedClass(string $legacy): ?string
    {
        if (preg_match('/\A'.preg_quote(LegacySourceMap::NamespacePrefix, '/').'(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*\z/', $legacy) !== 1) {
            return null;
        }
        $renamed = 'Heptapod\\'.substr($legacy, strlen(LegacySourceMap::NamespacePrefix));

        return class_exists($renamed) || interface_exists($renamed) || enum_exists($renamed) ? $renamed : null;
    }

    /** Encodes a changed string token with the slash and unicode escaping style of the original token. */
    private function encodeJsonString(string $value, string $originalToken): string
    {
        $flags = JSON_THROW_ON_ERROR;
        if (! str_contains($originalToken, '\\/')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }
        if (preg_match('/\\\\u(?!00[0-7])/i', $originalToken) !== 1) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        return json_encode($value, $flags);
    }

    /**
     * A report inside a scanned storage disk would be a target file that the storage preflight rejects.
     * The existing parent directory is resolved first, so symbolic links and '..' cannot hide a storage target.
     */
    public function assertReportOutsideStorage(string $reportPath): void
    {
        $parent = realpath(dirname($reportPath));
        if ($parent === false || ! is_dir($parent)) {
            throw new ImportFailure(__('The report directory :directory must already exist.', ['directory' => dirname($reportPath)]), 2);
        }
        $absolute = $parent.'/'.basename($reportPath);
        foreach (['private' => 'local', 'public' => 'public'] as $prefix => $diskName) {
            if (config('filesystems.disks.'.$diskName.'.driver') !== 'local') {
                continue;
            }
            $root = realpath(Storage::disk($diskName)->path(''));
            if ($root === false || ! str_starts_with($absolute, rtrim($root, '/').'/')) {
                continue;
            }
            $relative = substr($absolute, strlen(rtrim($root, '/')) + 1);
            if (! $this->excludedStoragePath($prefix.'/'.$relative)) {
                throw new ImportFailure(__('The report path :path is inside the storage disk ":disk" that the import verifies. Write the report outside storage or below :directory.', ['path' => $reportPath, 'disk' => $diskName, 'directory' => Storage::disk('local')->path(LegacySourceMap::ImportName)]), 2);
            }
        }
    }

    /** @param Closure(string, int, float): void $progress
     * @return array{class_names: list<array{table: string, column: string, count: int, first_ids: list<string>}>, brand_rows: list<array{table: string, column: string, count: int}>}
     */
    public function scan(Closure $progress): array
    {
        /** @var list<object{table_name: string, column_name: string}> $columns */
        $columns = $this->connection()->select("SELECT c.table_name, c.column_name FROM information_schema.columns c JOIN pg_catalog.pg_class t ON t.relname = c.table_name JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace AND n.nspname = c.table_schema WHERE c.table_schema = 'public' AND c.data_type IN ('text','character varying','character','json','jsonb') AND t.relkind IN ('r','p') AND NOT t.relispartition ORDER BY c.table_name, c.ordinal_position");
        $hits = [];
        $brands = [];
        $currentTable = null;
        $tableStart = microtime(true);
        $rows = 0;
        foreach ($columns as $column) {
            $table = $column->table_name;
            if ($table !== $currentTable) {
                if ($currentTable !== null) {
                    $progress($currentTable, $rows, microtime(true) - $tableStart);
                }
                $currentTable = $table;
                $tableStart = microtime(true);
            }
            $name = $column->column_name;
            $sqlTable = $this->identifier($table);
            $sqlColumn = $this->identifier($name);
            // Require a namespace segment: a JSON-escaped quote, newline or tab is not a class name.
            /** @var object{rows: int, classes: int, brands: int}|null $row */
            $row = $this->connection()->selectOne("SELECT count(*) AS rows, count(*) FILTER (WHERE {$sqlColumn}::text ~ ?) AS classes, count(*) FILTER (WHERE strpos(lower({$sqlColumn}::text), lower(?)) > 0 AND NOT ({$sqlColumn}::text ~ ?)) AS brands FROM {$sqlTable}", [LegacySourceMap::ClassNamePattern, LegacySourceMap::Brand, LegacySourceMap::ClassNamePattern]);
            if ($row === null) {
                throw new ImportFailure('Residual scan returned no aggregate.', 6);
            }
            $rows = $row->rows;
            if ($row->classes > 0) {
                // Pivot tables have no id; tableoid/ctid identifies their row without exposing payloads.
                $locator = $this->connection()->getSchemaBuilder()->hasColumn($table, 'id') ? 'id::text' : "tableoid::regclass::text || ':' || ctid::text";
                /** @var list<object{id: string}> $ids */
                $ids = $this->connection()->select("SELECT {$locator} AS id FROM {$sqlTable} WHERE {$sqlColumn}::text ~ ? LIMIT 5", [LegacySourceMap::ClassNamePattern]);
                $firstIds = [];
                foreach ($ids as $id) {
                    $firstIds[] = $id->id;
                }
                $hits[] = ['table' => $table, 'column' => $name, 'count' => $row->classes, 'first_ids' => $firstIds];
            }
            if ($row->brands > 0) {
                $brands[] = ['table' => $table, 'column' => $name, 'count' => $row->brands];
            }
        }

        if ($currentTable !== null) {
            $progress($currentTable, $rows, microtime(true) - $tableStart);
        }

        return ['class_names' => $hits, 'brand_rows' => $brands];
    }

    /** @param array<string, array{size: int, sha256: string}> $entries
     * @return array<string, array{size: int, sha256: string, disk: string, path: string}>
     */
    public function storageManifest(array $entries): array
    {
        $files = [];
        foreach ($entries as $path => $entry) {
            if (! str_starts_with($path, 'storage/app/')) {
                if (! str_starts_with($path, 'db-dumps/')) {
                    throw new ImportFailure('Archive contains an unsupported file outside storage/app and db-dumps.', 3);
                }

                continue;
            }
            $relative = substr($path, strlen('storage/app/'));
            if ($relative === '.gitignore' || $this->excludedStoragePath($relative)) {
                continue;
            }
            $parts = explode('/', $relative, 2);
            $disk = match ($parts[0]) {
                'private' => 'local',
                'public' => 'public',
                default => throw new ImportFailure(__('Unmapped storage root entry: :path', ['path' => $relative]), 3),
            };
            if (! isset($parts[1]) || $parts[1] === '') {
                throw new ImportFailure('Archive storage entry has no disk-relative path.', 3);
            }
            $files[$relative] = $entry + ['disk' => $disk, 'path' => $parts[1]];
        }

        return $files;
    }

    /** @param array<string, array{size: int, sha256: string}> $entries
     * @return list<string>
     */
    public function skippedStorageEntries(array $entries): array
    {
        $skipped = [];
        foreach (array_keys($entries) as $path) {
            if (str_starts_with($path, 'storage/app/')) {
                $relative = substr($path, strlen('storage/app/'));
                if ($relative === '.gitignore' || $this->excludedStoragePath($relative)) {
                    $skipped[] = $path;
                }
            }
        }

        return $skipped;
    }

    /** @param array<string, array{size: int, sha256: string, disk: string, path: string}> $files */
    public function assertStorageTarget(array $files): void
    {
        foreach (['private' => 'local', 'public' => 'public'] as $prefix => $diskName) {
            $disk = Storage::disk($diskName);
            foreach ($disk->allFiles() as $path) {
                $relative = $prefix.'/'.$path;
                if ($this->excludedStoragePath($relative)) {
                    continue;
                }
                if (! isset($files[$relative]) || $this->diskHash($diskName, $path) !== $files[$relative]['sha256']) {
                    throw new ImportFailure(__('Target storage contains a non-identical file: disk ":disk", path ":path". Use an empty storage volume or remove the conflict deliberately.', ['disk' => $diskName, 'path' => $path]), 2);
                }
            }
        }
        foreach ($files as $entry) {
            if (config('filesystems.disks.'.$entry['disk'].'.driver') === 'local') {
                $this->assertNoSymlink(Storage::disk($entry['disk'])->path($entry['path']));
            }
        }
    }

    /** @param array<string, array{size: int, sha256: string, disk: string, path: string}> $files
     * @return list<array{path: string, disk: string, size: int, source_sha256: string, target_sha256: string}>
     */
    public function copyStorage(string $extraction, array $files): array
    {
        $copied = [];
        foreach ($files as $relative => $entry) {
            $disk = Storage::disk($entry['disk']);
            if (config('filesystems.disks.'.$entry['disk'].'.driver') === 'local') {
                $this->assertNoSymlink($disk->path($entry['path']));
            }
            if (! $disk->exists($entry['path'])) {
                $input = fopen($extraction.'/storage/app/'.$relative, 'rb');
                if ($input === false) {
                    throw new ImportFailure('Cannot read an extracted storage file.', 9);
                }
                try {
                    if ($disk->put($entry['path'], $input) === false) {
                        throw new ImportFailure('Cannot copy a storage file.', 9);
                    }
                } finally {
                    fclose($input);
                }
            }
            $sha256 = $this->diskHash($entry['disk'], $entry['path']);
            $copied[] = ['path' => $entry['path'], 'disk' => $entry['disk'], 'size' => $entry['size'], 'source_sha256' => $entry['sha256'], 'target_sha256' => $sha256];
            if ($sha256 !== $entry['sha256']) {
                throw new ImportFailure('Storage checksum mismatch.', 9);
            }
        }

        return $copied;
    }

    private function diskHash(string $disk, string $path): string
    {
        $input = Storage::disk($disk)->readStream($path);
        if (! is_resource($input)) {
            throw new ImportFailure('Cannot read a target storage file for checksum verification.', 9);
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $input);

            return hash_final($hash);
        } finally {
            fclose($input);
        }
    }

    /** @return array{media_expected: int, media_present: int, icons_expected: int, icons_present: int, missing_media_ids: list<int>, missing_site_ids: list<int>} */
    public function verifyMedia(): array
    {
        $result = ['media_expected' => 0, 'media_present' => 0, 'icons_expected' => 0, 'icons_present' => 0, 'missing_media_ids' => [], 'missing_site_ids' => []];
        /** @var list<object{disk: string, count: int}> $disks */
        $disks = $this->connection()->select("SELECT referenced.disk, count(DISTINCT media.id) AS count FROM media CROSS JOIN LATERAL (VALUES (media.disk), (media.conversions_disk)) AS referenced(disk) WHERE referenced.disk IS NOT NULL AND referenced.disk <> '' GROUP BY referenced.disk");
        foreach ($disks as $disk) {
            if (! config()->has('filesystems.disks.'.$disk->disk)) {
                throw new ImportFailure(__('Media disk is not configured: :disk (rows: :count).', ['disk' => $disk->disk, 'count' => $disk->count]), 9);
            }
        }
        foreach (Media::query()->lazyById(500) as $media) {
            $result['media_expected']++;
            $disk = Storage::disk($media->disk);
            $path = $media->getPathRelativeToRoot();
            if ($disk->exists($path) && $disk->size($path) === $media->size) {
                $result['media_present']++;
            } else {
                $result['missing_media_ids'][] = $media->id;
            }
        }
        foreach (Site::query()->where('icon_hash', '!=', null)->lazyById(500) as $site) {
            $result['icons_expected']++;
            $path = 'site-icons/'.$site->id.'/'.$site->icon_hash.'.png';
            if (Storage::disk()->exists($path) && Storage::disk()->size($path) > 0) {
                $result['icons_present']++;
            } else {
                $result['missing_site_ids'][] = $site->id;
            }
        }

        return $result;
    }

    /**
     * Runs the migrations and records which settings rows each settings migration wrote.
     * Attribution is observed, not inferred: the settings table is read when the migration runner starts and ends
     * each SettingsMigration (same connection, so uncommitted writes are visible). A settings change made
     * outside such a window, by any other migration or process, stays unattributed and fails verification.
     *
     * @param  Closure(): void  $migrate
     * @return array{source: array<string, string>, writes: array<string, array<string, string>>} source and written payloads by "group.name"; writes by migration name
     */
    public function migrateRecordingSettings(Closure $migrate): array
    {
        $source = $this->settingsPayloads();
        $writes = [];
        $before = null;
        Event::listen(MigrationStarted::class, function (MigrationStarted $event) use (&$before): void {
            $before = $event->method === 'up' && $event->migration instanceof SettingsMigration ? $this->settingsPayloads() : null;
        });
        Event::listen(MigrationEnded::class, function (MigrationEnded $event) use (&$before, &$writes): void {
            if ($before === null) {
                return;
            }
            if ($event->name === null) {
                throw new ImportFailure('A settings migration ran without a migration name.', 5);
            }
            $written = array_diff_assoc($this->settingsPayloads(), $before);
            if ($written !== []) {
                $writes[$event->name] = $written;
            }
            $before = null;
        });
        $migrate();

        return ['source' => $source, 'writes' => $writes];
    }

    /**
     * Compares the target settings with the source rows. A source key must keep its payload, unless a settings
     * migration applied in this import wrote exactly the current payload. A key absent from the source must have
     * been added by such a migration with exactly the current payload. Everything else is a mismatch.
     *
     * @param  array{source: array<string, string>, writes: array<string, array<string, string>>}  $recorded
     * @param  list<string>  $applied
     * @return array{added: array<string, string>, rewritten: array<string, string>, mismatches: list<array{table: string, key: string, problem: string}>}
     */
    public function settingsVerification(array $recorded, array $applied): array
    {
        /** @var array<string, array{migration: string, payload: string}> $lastWrite */
        $lastWrite = [];
        foreach ($recorded['writes'] as $migration => $written) {
            if (! in_array($migration, $applied, true)) {
                throw new ImportFailure(__('Settings were written by a migration outside this import: :migration', ['migration' => $migration]), 7);
            }
            foreach ($written as $key => $payload) {
                $lastWrite[$key] = ['migration' => $migration, 'payload' => $payload];
            }
        }
        $target = $this->settingsPayloads();
        $result = ['added' => [], 'rewritten' => [], 'mismatches' => []];
        foreach ($recorded['source'] as $key => $payload) {
            if (! array_key_exists($key, $target)) {
                $result['mismatches'][] = ['table' => 'settings', 'key' => $key, 'problem' => 'source row missing'];
            } elseif ($target[$key] !== $payload) {
                if (($lastWrite[$key]['payload'] ?? null) === $target[$key]) {
                    $result['rewritten'][$key] = $lastWrite[$key]['migration'];
                } else {
                    $result['mismatches'][] = ['table' => 'settings', 'key' => $key, 'problem' => 'source payload changed outside an applied settings migration'];
                }
            }
        }
        foreach (array_diff_key($target, $recorded['source']) as $key => $payload) {
            if (($lastWrite[$key]['payload'] ?? null) === $payload) {
                $result['added'][$key] = $lastWrite[$key]['migration'];
            } else {
                $result['mismatches'][] = ['table' => 'settings', 'key' => $key, 'problem' => 'row not in the source and not added by an applied settings migration'];
            }
        }

        return $result;
    }

    /** @return array<string, string> payload JSON text by "group.name" */
    private function settingsPayloads(): array
    {
        $payloads = [];
        /** @var list<object{group: string, name: string, payload: string}> $rows */
        $rows = $this->connection()->select('SELECT "group", name, payload::text AS payload FROM settings ORDER BY id');
        foreach ($rows as $row) {
            $payloads[$row->group.'.'.$row->name] = $row->payload;
        }

        return $payloads;
    }

    /** @param array<string, int> $source
     * @param  list<string>  $applied
     * @param  int  $settingsAdded  settings rows that verified settings migrations of this import added
     * @param  Closure(string, int, float): void  $progress
     * @return array<string, array{source: int, expected: int, target: int, disposition: string}>
     */
    public function counts(array $source, array $applied, int $settingsAdded, Closure $progress): array
    {
        /** @var list<object{parent: string, child: string}> $parents */
        $parents = $this->connection()->select('SELECT parent.relname AS parent, child.relname AS child FROM pg_catalog.pg_inherits i JOIN pg_catalog.pg_class parent ON parent.oid = i.inhparent JOIN pg_catalog.pg_class child ON child.oid = i.inhrelid JOIN pg_catalog.pg_namespace n ON n.oid = parent.relnamespace WHERE n.nspname = \'public\'');
        $expected = $source;
        // pg_dump COPYs partition leaves. Parent count(*) includes those leaves, rather than zero COPY rows.
        foreach ($parents as $partition) {
            $expected[$partition->parent] = ($expected[$partition->parent] ?? 0) + ($source[$partition->child] ?? 0);
        }
        $result = [];
        foreach (array_keys($source) as $table) {
            $start = microtime(true);
            $dropped = in_array($table, self::DroppedTables, true);
            $wanted = $dropped ? 0 : $expected[$table] + match ($table) {
                'migrations' => count($applied),
                'settings' => $settingsAdded,
                default => 0,
            };
            $result[$table] = ['source' => $expected[$table], 'expected' => $wanted, 'target' => $this->connection()->table($table)->count(), 'disposition' => $dropped ? 'dropped' : 'preserved'];
            $progress($table, $result[$table]['target'], microtime(true) - $start);
        }

        return $result;
    }

    /** @return list<array{table: string, column: string, last_value: int, max_id: int, ok: bool}> */
    public function sequences(): array
    {
        /** @var list<object{table_name: string, column_name: string, sequence: string|null}> $columns */
        // Partition leaves inherit the parent's default, but do not own a serial sequence.
        $columns = $this->connection()->select("SELECT c.table_name, c.column_name, pg_get_serial_sequence(quote_ident(c.table_schema)||'.'||quote_ident(c.table_name), c.column_name) AS sequence FROM information_schema.columns c JOIN pg_catalog.pg_class t ON t.relname = c.table_name JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace AND n.nspname = c.table_schema WHERE c.table_schema = 'public' AND NOT t.relispartition AND (c.column_default LIKE 'nextval(%' OR c.is_identity = 'YES')");
        $result = [];
        foreach ($columns as $column) {
            if ($column->sequence === null) {
                throw new ImportFailure('A serial column has no associated sequence.', 7);
            }
            $sequenceParts = explode('.', $column->sequence);
            $sequence = implode('.', array_map($this->identifier(...), $sequenceParts));
            /** @var object{last_value: int}|null $last */
            $last = $this->connection()->selectOne('SELECT last_value FROM '.$sequence);
            /** @var int|null $maximum */
            $maximum = $this->connection()->table($column->table_name)->max($column->column_name);
            $max = $maximum ?? 0;
            if ($last === null) {
                throw new ImportFailure('A restored sequence is unreadable.', 7);
            }
            $lastValue = $last->last_value;
            $result[] = ['table' => $column->table_name, 'column' => $column->column_name, 'last_value' => $lastValue, 'max_id' => $max, 'ok' => $lastValue >= $max];
        }

        return $result;
    }

    /** @return list<array{table: string, column: string, row_id: int|null, result: string}> */
    public function decryption(): array
    {
        $this->assertEncryptedRegistry();
        $result = [];
        foreach (self::EncryptedColumns as $modelClass => $columns) {
            foreach ($columns as $column) {
                $model = $modelClass::query()->where($column, '!=', null)->oldest('id')->first(['id', $column]);
                $status = 'n/a';
                if ($model !== null) {
                    try {
                        $model->getAttribute($column);
                        $status = 'ok';
                    } catch (Throwable) {
                        // The result deliberately contains no ciphertext, plaintext or exception message.
                        $status = 'fail';
                    }
                }
                $id = $model?->getKey();
                if ($id !== null && ! is_int($id)) {
                    throw new ImportFailure('Encrypted model row IDs must be integers.', 8);
                }
                $result[] = ['table' => (new $modelClass)->getTable(), 'column' => $column, 'row_id' => $id, 'result' => $status];
            }
        }

        foreach (self::FortifyEncryptedColumns as $column) {
            /** @phpstan-var (\stdClass&object{id: int, ciphertext: string})|null $row */
            $row = $this->connection()->table('users')->whereNotNull($column)->orderBy('id')->first(['id', $column.' as ciphertext']);
            $status = 'n/a';
            if ($row !== null) {
                try {
                    Fortify::currentEncrypter()->decrypt($row->ciphertext);
                    $status = 'ok';
                } catch (Throwable) {
                    $status = 'fail';
                }
            }
            $result[] = ['table' => 'users', 'column' => $column, 'row_id' => $row?->id, 'result' => $status];
        }

        return $result;
    }

    private function assertEncryptedRegistry(): void
    {
        $found = [];
        $paths = glob(app_path('Models/*.php'));
        if ($paths === false) {
            throw new ImportFailure('Cannot inspect the encrypted model registry.', 2);
        }
        foreach ($paths as $path) {
            $class = app()->getNamespace().'Models\\'.basename($path, '.php');
            if (! is_subclass_of($class, Model::class) || new \ReflectionClass($class)->isAbstract()) {
                continue;
            }
            foreach ((new $class)->getCasts() as $column => $cast) {
                if (str_starts_with($cast, 'encrypted')) {
                    $found[$class][] = $column;
                }
            }
        }
        $expected = self::EncryptedColumns;
        foreach ($found as &$columns) {
            sort($columns);
        }
        unset($columns);
        foreach ($expected as &$columns) {
            sort($columns);
        }
        unset($columns);
        ksort($found);
        ksort($expected);
        if ($found !== $expected) {
            throw new ImportFailure('Encrypted model casts changed: update the importer encrypted-column registry before importing.', 2);
        }
        // Empty-target preflight has no users table. After restore, guard every textual Fortify 2FA column.
        if ($this->connection()->getSchemaBuilder()->hasTable('users')) {
            /** @var list<string> $fortifyColumns */
            $fortifyColumns = $this->connection()->table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'users')
                ->where('column_name', 'like', 'two\\_factor\\_%')->whereIn('data_type', ['text', 'character varying', 'character'])->pluck('column_name')->all();
            $expectedFortify = self::FortifyEncryptedColumns;
            sort($fortifyColumns);
            sort($expectedFortify);
            if ($fortifyColumns !== $expectedFortify) {
                throw new ImportFailure('Fortify encrypted columns changed: update the importer encrypted-column registry before importing.', 8);
            }
        }
    }

    private function excludedStoragePath(string $path): bool
    {
        $parts = explode('/', $path);
        $first = in_array($parts[0], ['private', 'public'], true) ? ($parts[1] ?? '') : $parts[0];

        return in_array($first, ['backup-temp', LegacySourceMap::ImportName, LegacySourceMap::BackupDirectory], true);
    }

    private function assertNoSymlink(string $path): void
    {
        while ($path !== dirname($path)) {
            if (is_link($path)) {
                throw new ImportFailure('A target storage path contains a symbolic link.', 9);
            }
            $path = dirname($path);
        }
    }

    private function connectionString(string $key): string
    {
        $value = $this->connection()->getConfig($key);
        if (! is_string($value) && ! is_int($value)) {
            throw new ImportFailure(__('Invalid PostgreSQL connection configuration: :key', ['key' => $key]), 2);
        }

        return (string) $value;
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
