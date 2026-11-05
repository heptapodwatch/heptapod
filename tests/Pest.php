<?php

declare(strict_types=1);

use Heptapod\Testing\BrowserResponseFraming;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\ApiTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Browser', '../addons/uptime/tests', '../addons/performance/tests', '../addons/crawl/tests');

pest()->extend(ApiTestCase::class)->in('Feature');

// The upgrade dry-run issues SET TRANSACTION READ ONLY, which poisons RefreshDatabase's wrapping test transaction.
$transactionalFeaturePaths = array_filter(glob(__DIR__.'/Feature/*'), fn (string $path): bool => $path !== __DIR__.'/Feature/ReclassifyLinksUpgradeTest.php');
pest()->use(RefreshDatabase::class)->in(...$transactionalFeaturePaths);

pest()->beforeEach(function (): void {
    Event::listen(RequestHandled::class, BrowserResponseFraming::class);
})->in('Browser');

// Manual network smoke tests are outside the PHPUnit/CI suite and do not write database state.
pest()->extend(TestCase::class)->in('../addons/channels/tests/Manual');

// The dedicated 0.1-CPU reproduction peaked at 17.81 seconds per browser case.
// Allow 3x that measured bound, rounded up to 60 seconds per action/condition,
// not per test. This is load tolerance; no server-readiness defect was found.
pest()->browser()->timeout(60_000);

pest()->tia()->directory(getenv('HEPTAPOD_TIA_BROWSER') === '1' ? '.pest/tia-browser' : '.pest/tia')->defaultBranch('main')
    ->watch([
        'addons/*/database/migrations/**/*.php' => 'addons',
        'addons/*/lang/**' => 'addons',
        'addons/*/resources/views/**' => 'addons',
    ])->watch([
        'addons/*/database/migrations/**/*.php' => 'tests',
        'addons/*/lang/**' => 'tests',
        'addons/*/resources/views/**' => 'tests',
    ]);
