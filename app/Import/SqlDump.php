<?php

declare(strict_types=1);

namespace Heptapod\Import;

use Generator;
use SplFileObject;

/** The supported source is a plain pg_dump with COPY data, not SQL inserts or a custom-format archive. */
final class SqlDump
{
    /** @return array{tables: array<string, int>, migrations: list<string>, highest_migration: string, header: list<string>, class_names: array<string, int>, brand_rows: array<string, int>, filtered_statements: int} */
    public function manifest(string $path): array
    {
        $tables = [];
        $migrations = [];
        $header = [];
        $classNames = [];
        $brandRows = [];
        $filtered = 0;
        $table = null;
        $migrationIndex = null;
        $migrationIdIndex = null;
        $highestMigrationId = -1;
        $highestMigration = '';
        foreach ($this->lines($path) as $line) {
            if ($table !== null) {
                if (rtrim($line, "\r\n") === '\\.') {
                    $table = null;

                    continue;
                }
                $tables[$table]++;
                if ($migrationIndex !== null && $table === 'migrations') {
                    $values = explode("\t", rtrim($line, "\r\n"));
                    $migration = $values[$migrationIndex] ?? throw new ImportFailure('Invalid migrations COPY row.', 3);
                    $migrations[] = $migration;
                    $id = $migrationIdIndex === null ? null : ($values[$migrationIdIndex] ?? null);
                    if ($id === null || ! ctype_digit($id)) {
                        throw new ImportFailure('Invalid migration row ID in the source dump.', 3);
                    }
                    if ((int) $id > $highestMigrationId) {
                        $highestMigrationId = (int) $id;
                        $highestMigration = $migration;
                    }
                }
                if (preg_match('~'.LegacySourceMap::ClassNamePattern.'~', $line) === 1) {
                    $classNames[$table] = ($classNames[$table] ?? 0) + 1;
                } elseif (stripos($line, LegacySourceMap::Brand) !== false) {
                    $brandRows[$table] = ($brandRows[$table] ?? 0) + 1;
                }

                continue;
            }
            if (str_starts_with($line, '-- Dumped ') || preg_match('/^-- (?:Database:|Name: .*; Type: DATABASE;)/', $line) === 1) {
                $header[] = trim($line);
            }
            if (preg_match('/^CREATE TABLE public\.([a-z_][a-z0-9_]*)\s*\(/', $line, $matches) === 1) {
                $tables[$matches[1]] = 0;
            }
            if (preg_match('/^COPY public\.([a-z_][a-z0-9_]*) \(([^)]+)\) FROM stdin;\s*$/', $line, $matches) === 1) {
                $table = $matches[1];
                $tables[$table] ??= 0;
                if ($table === 'migrations') {
                    $columns = array_map(trim(...), explode(',', $matches[2]));
                    $index = array_search('migration', $columns, true);
                    if ($index === false) {
                        throw new ImportFailure('The migrations COPY block has no migration column.', 3);
                    }
                    $migrationIndex = $index;
                    $idIndex = array_search('id', $columns, true);
                    if ($idIndex === false) {
                        throw new ImportFailure('The migrations COPY block has no id column.', 3);
                    }
                    $migrationIdIndex = $idIndex;
                }

                continue;
            }
            if (preg_match('/^(?:COPY|INSERT INTO)\s/i', $line) === 1) {
                throw new ImportFailure('Unsupported dump data format: expected public-schema COPY blocks.', 3);
            }
            if ($this->omitStatement($line)) {
                $filtered++;
            }
        }
        if ($table !== null || $migrations === [] || $tables === []) {
            throw new ImportFailure('The source dump is incomplete or has no migration manifest.', 3);
        }
        ksort($tables);

        return ['tables' => $tables, 'migrations' => $migrations, 'highest_migration' => $highestMigration, 'header' => $header, 'class_names' => $classNames, 'brand_rows' => $brandRows, 'filtered_statements' => $filtered];
    }

    /** @return Generator<int, string> */
    public function portableSql(string $path, int $statementTimeoutMilliseconds): Generator
    {
        $copy = false;
        foreach ($this->lines($path) as $line) {
            if ($copy) {
                yield $line;
                if (rtrim($line, "\r\n") === '\\.') {
                    $copy = false;
                }
            } elseif (str_starts_with($line, 'COPY ')) {
                $copy = true;
                yield $line;
            } elseif (preg_match('/^SET statement_timeout\s*=/i', $line) === 1) {
                // pg_dump normally disables this timeout; keep the import's explicit per-statement bound.
                yield 'SET statement_timeout = '.$statementTimeoutMilliseconds.";\n";
            } elseif (! $this->omitStatement($line)) {
                yield $line;
            }
        }
    }

    private function omitStatement(string $line): bool
    {
        if (preg_match('/^\s*(?:(?:CREATE|ALTER)\s+ROLE\b|CREATE\s+DATABASE\b|\\\\(?:connect|c)(?:\s|$))/i', $line) === 1) {
            throw new ImportFailure('Dump contains a forbidden role, database, or connection statement.', 4);
        }

        // Old backups contain source-role ownership and ACLs. COPY rows must never pass through this filter.
        return preg_match('/^\s*(?:ALTER\b.*\bOWNER TO\b.*;\s*$|(?:GRANT|REVOKE)\b)/i', $line) === 1;
    }

    /** @return Generator<int, string> */
    private function lines(string $path): Generator
    {
        $file = new SplFileObject($path, 'r');
        while (! $file->eof()) {
            $line = $file->fgets();
            if ($line !== '') {
                yield $line;
            }
        }
    }
}
