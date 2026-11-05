<?php

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/addons',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/.worktrees/*',
        // Laravel regenerates these manifests during Composer install.
        __DIR__.'/bootstrap/cache/*',
        // First-class callables evaluate their receiver before Inertia selects lazy props.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__.'/app/Http/Controllers/*',
            __DIR__.'/app/Queries/*',
        ],
        // These strings are stored data, not references to the current classes; they must survive class renames.
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/database/migrations/2026_10_16_150000_replace_class_names_with_morph_aliases.php',
        ],
    ])
    // Rector sizes its worker pool from the host core count, not the container's CPU quota. The dev app container has
    // a 4-CPU quota on a 32-core host, so 32 workers starved each other into the 120 s child timeout on full runs.
    ->withParallel(maxNumberOfProcess: 4)
    ->withPhpSets()
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    ->withComposerBased(laravel: true);
