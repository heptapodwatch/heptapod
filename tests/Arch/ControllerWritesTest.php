<?php

use Tests\Arch\ArchDrift;

// Route and console boundaries delegate persistence to the invokable domain actions.
arch('controllers and console commands never write Eloquent models', function (): void {
    $roots = [dirname(__DIR__, 2).'/app/Http/Controllers', dirname(__DIR__, 2).'/app/Console/Commands'];
    foreach (glob(dirname(__DIR__, 2).'/addons/*/src') as $addonSource) {
        $roots[] = $addonSource;
    }
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (str_starts_with($root, dirname(__DIR__, 2).'/addons/') && ! str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
            foreach (ArchDrift::calls($file->getPathname(), ['transaction', 'save', 'savequietly', 'update', 'updatequietly', 'create', 'createquietly', 'delete', 'deletequietly', 'destroy', 'insert', 'upsert', 'firstorcreate', 'updateorcreate', 'forcefill', 'attach', 'detach', 'sync', 'synctags', 'attachtag'], true) as $call) {
                expect(ArchDrift::allowed(ArchDrift::ControllerWrites, $relative, $call['method'], $call['call'])
                    || ($call['call'] === 'transaction' && ArchDrift::allowed(ArchDrift::ReadSnapshotTransactions, $relative, $call['method'], $call['call'])))
                    ->toBeTrue("{$relative}:{$call['line']} {$call['method']}() calls {$call['call']}; delegate persistence to an action.");
            }
        }
    }
});
