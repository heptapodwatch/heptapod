<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration)
    ->addPathsToExclude(array_filter([__DIR__.'/.worktrees'], is_dir(...)))
    ->addPathToExclude(__DIR__.'/bootstrap/cache')
    ->addPathToScan(__DIR__.'/bootstrap', isDev: false)
    ->addPathToScan(__DIR__.'/config', isDev: false)
    ->addPathToScan(__DIR__.'/routes', isDev: false)
    // Test-only seams remain in core's Testing namespace, enforced by the architecture suite.
    ->addPathToScan(__DIR__.'/app/Testing', isDev: true)
    // Laravel resolves the S3 driver by name, not by an application class import.
    ->ignoreErrorsOnPackage('league/flysystem-aws-s3-v3', [ErrorType::UNUSED_DEPENDENCY])
    // Dev-only fixtures drive real addon execution under fake external seams.
    ->addPathToScan(__DIR__.'/database/seeders/DemoSeeder.php', isDev: true)
    ->addPathToScan(__DIR__.'/database/seeders/AgencyDemoSeeder.php', isDev: true)
    ->addPathToScan(__DIR__.'/database/seeders/ReviewStatesSeeder.php', isDev: true)
    ->addPathToScan(__DIR__.'/database/seeders/Support/RecordsReviewRuns.php', isDev: true)
    // These packages are invoked through Artisan or registered by package discovery.
    ->ignoreErrorsOnPackage('laravel/tinker', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('laravel/reverb', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('laravel/wayfinder', [ErrorType::UNUSED_DEPENDENCY])
    // Production discovers the uptime provider from its Composer manifest, not a core import.
    ->ignoreErrorsOnPackage('heptapod/addon-uptime', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    // Production discovers the performance provider from its Composer manifest, not a core import.
    ->ignoreErrorsOnPackage('heptapod/addon-performance', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    // Certificate and cron providers are production Composer-discovered packages; core imports only contracts.
    ->ignoreErrorsOnPackage('heptapod/addon-certificate_health', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    ->ignoreErrorsOnPackage('heptapod/addon-cron', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    // Production discovers the channels provider from its Composer manifest, not a core import.
    ->ignoreErrorsOnPackage('heptapod/addon-channels', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    ->ignoreErrorsOnPackage('heptapod/addon-dns', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    // Application health is a production Composer-discovered package; core imports only contracts.
    ->ignoreErrorsOnPackage('heptapod/addon-application_health', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    ->ignoreErrorsOnPackage('heptapod/addon-domain', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    ->ignoreErrorsOnPackage('heptapod/addon-sitemap', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    ->ignoreErrorsOnPackage('heptapod/addon-crawl', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    ->ignoreErrorsOnPackage('heptapod/addon-lighthouse', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);
