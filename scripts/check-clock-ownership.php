<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$databaseClockSql = '/\b(?:CURRENT_(?:TIMESTAMP|DATE|TIME)|LOCAL(?:TIMESTAMP|TIME))\b|\b(?:clock_timestamp|statement_timestamp|transaction_timestamp|now|timeofday)\s*\(/i';

// Exact historical files only: a new migration must supply its time explicitly.
$sqlOwners = [
    'app/Checks/PostgresDatabaseClock.php' => 'The production database-clock adapter.',
    'app/Actions/RequestRoutingReconciliation.php' => 'Revision token compared only for equality, not as a time.',
    'app/Support/Api/Idempotency.php' => 'TODO: S4 moves the expiry comparison to the app clock (C14).',
    'database/migrations/0001_01_01_000002_create_jobs_table.php' => 'Already-run failed_at default.',
    'database/migrations/2026_10_01_170000_create_monitoring_domain.php' => 'Already-run next_run_at and snooze defaults; S0c contracts next_run_at later.',
    'database/migrations/2026_10_12_074627_mark_accepted_incident_openings.php' => 'Already-run incident-opening backfill (C15).',
    'database/migrations/2026_10_12_085103_create_list_response_buckets.php' => 'Already-run response-bucket backfill (C15).',
    'database/migrations/2026_10_13_071507_use_fixed_grid_list_response_buckets.php' => 'Already-run fixed-grid backfill (C15).',
    'database/migrations/2026_10_13_190802_create_event_flap_groups.php' => 'Already-run event-flap backfill (C15).',
];
$clockOwners = [
    'app/Checks/Execution/ClaimService.php' => 'Dispatch and lease ownership.',
    'app/Checks/Execution/CheckExecutor.php' => 'Running lease ownership.',
    'app/Checks/Recording/TransactionalResultRecorder.php' => 'Result schedule ownership.',
    'app/Actions/UpdateCheckConfig.php' => 'Initial and changed check schedules.',
    'app/Console/Commands/RebalanceCheckScheduleCommand.php' => 'Schedule rebalancing.',
    'app/Actions/RecordDispatcherHeartbeat.php' => 'Dispatcher heartbeat ownership.',
    'database/factories/CheckFactory.php' => 'Explicit check schedule fixtures.',
    'app/Providers/AppServiceProvider.php' => 'Production seam binding.',
    'app/Checks/PostgresDatabaseClock.php' => 'Production seam implementation.',
    'app/Testing/OffsetDatabaseClock.php' => 'Deterministic seam implementation.',
    'app/Console/Commands/ReclassifyLinksCommand.php' => 'TODO: S4 moves these readers to the app clock (C10–C13).',
    'app/Actions/SetNotificationDestinationEnabled.php' => 'TODO: S4 moves this reader to the app clock (C10–C13).',
    'app/Actions/ResolveInactiveCheckIncidents.php' => 'TODO: S4 moves this reader to the app clock (C10–C13).',
];
$addonDirectories = glob($root.'/addons/*/src', GLOB_ONLYDIR);
if ($addonDirectories === false) {
    throw new RuntimeException('Cannot list addon source directories.');
}
$directories = ['app', 'database/migrations', 'database/factories', 'database/seeders', ...$addonDirectories];
$files = [];
foreach ($directories as $directory) {
    $absolute = str_starts_with($directory, $root.'/') ? $directory : $root.'/'.$directory;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);
$failures = [];
foreach ($files as $path) {
    $source = file_get_contents($root.'/'.$path);
    if ($source === false) {
        throw new RuntimeException('Cannot read '.$path);
    }
    $code = '';
    $previousToken = null;
    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            $code .= $token;
            $previousToken = $token;

            continue;
        }
        [$kind, $text, $line] = $token;
        if (in_array($kind, [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            $code .= $kind === T_WHITESPACE ? $text : ' ';

            continue;
        }
        $literal = in_array($kind, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
        if ($literal && ! isset($sqlOwners[$path]) && preg_match($databaseClockSql, $text) === 1) {
            $failures[$path.': rule 1'] = $path.':'.$line.': rule 1: database-clock SQL literal outside its owners';
        }
        $code .= $literal ? ' ' : $text;
        // Declaring the seam is not consuming it. All references, including aliases and class strings, are checked.
        $declaration = $path === 'app/Checks/DatabaseClock.php' && $previousToken === T_INTERFACE;
        if (! $declaration && ! isset($clockOwners[$path]) && preg_match('/DatabaseClock\b/i', $text) === 1) {
            $failures[$path.': rule 2'] = $path.':'.$line.': rule 2: DatabaseClock reference outside its owners';
        }
        $previousToken = $kind;
    }
    if (! isset($sqlOwners[$path]) && preg_match('/(?:->|\?->)\s*useCurrent\s*\(/i', $code) === 1) {
        $failures[$path.': rule 1 useCurrent'] = $path.': rule 1: useCurrent() outside its owners';
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Clock ownership guard failed:\n".implode("\n", $failures)."\n");
    exit(1);
}
echo 'Clock ownership guard: '.count($files)." PHP files; no forbidden SQL clocks or DatabaseClock consumers.\n";
