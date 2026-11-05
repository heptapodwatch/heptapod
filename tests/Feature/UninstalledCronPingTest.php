<?php

declare(strict_types=1);

use Heptapod\Addons\Cron\CronServiceProvider;
use Heptapod\Contracts\CronTaskStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

it('returns 404 for a ping when the cron addon provider is not booted', function (): void {
    $originalApp = app();
    $app = require Application::inferBasePath().'/bootstrap/app.php';
    $manifest = new PackageManifest(new Filesystem, $app->basePath(), $app->getCachedPackagesPath());
    $manifest->providers();
    unset($manifest->manifest['heptapod/addon-cron']);
    $app->instance(PackageManifest::class, $manifest);
    $app->make(Kernel::class)->bootstrap();
    $this->app = $app;
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    try {
        expect($app->providerIsLoaded(CronServiceProvider::class))->toBeFalse()
            ->and($app->bound(CronTaskStore::class))->toBeFalse();
        $this->postJson(route('cron.ping', ['uuid' => (string) Str::uuid()]))->assertNotFound();
    } finally {
        $this->app = $originalApp;
        Container::setInstance($originalApp);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($originalApp);
    }
});
