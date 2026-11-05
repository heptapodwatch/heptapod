<?php

use Tests\Arch\ArchDrift;

// Job::dispatch() does not reference Bus directly, so facade dependency checks cannot catch it.
test('controllers never dispatch jobs directly', function (): void {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Http/Controllers'));

    $violations = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        foreach (ArchDrift::calls($file->getPathname(), ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse', 'dispatchif', 'dispatchunless'], false) as $call) {
            if (! ArchDrift::allowed(ArchDrift::ControllerDispatch, $relative, $call['method'], $call['call'])) {
                $violations[] = "{$relative}:{$call['line']} {$call['method']}() calls {$call['call']}; dispatch through an action instead.";
            }
        }
    }
    expect($violations)->toBeEmpty();
});
