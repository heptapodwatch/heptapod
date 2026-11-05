<?php

declare(strict_types=1);

namespace Tests;

use Carbon\CarbonImmutable;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Contracts\DiskSpaceReader;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Contracts\LiveConnectionTerminator;
use Heptapod\Jobs\RefreshSiteIcon;
use Heptapod\Testing\FakeDiskSpaceReader;
use Heptapod\Testing\FakeLiveConnectionTerminator;
use Heptapod\Testing\FixtureOnlyDnsResolver;
use Heptapod\Testing\FixtureOnlyNetwork;
use Heptapod\Testing\FixtureOnlyTargetResolver;
use Heptapod\Testing\OffsetDatabaseClock;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * check_runs partitions every migrated test database has besides the migration's wall-clock months. The suite
     * freezes most tests in October 2026, when the migration created exactly these months.
     */
    public const array CheckRunPartitionEpochMonths = ['2026-09', '2026-10', '2026-11'];

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        // Registered before setUpTraits migrates, so RefreshDatabase, DatabaseTruncation and explicit migrate:fresh all get it.
        $app['events']->listen(MigrationsEnded::class, static function (MigrationsEnded $event): void {
            if ($event->method !== 'up') {
                return;
            }
            foreach (self::CheckRunPartitionEpochMonths as $month) {
                $start = CarbonImmutable::parse($month.'-01T00:00:00Z');
                DB::statement('CREATE TABLE IF NOT EXISTS check_runs_'.$start->format('Y_m')." PARTITION OF check_runs FOR VALUES FROM ('{$start->toIso8601String()}') TO ('{$start->addMonth()->toIso8601String()}')");
            }
        });

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Tests that commit fixtures for independent processes leave audit, event and role rows outside the
        // RefreshDatabase transaction; the next test in this worker must start from a freshly migrated database.
        Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event): void {
            if ($event->connection->transactionLevel() === 0) {
                RefreshDatabaseState::$migrated = false;
            }
        });
        $this->app->extend(PrivateTargetGuard::class, static fn (PrivateTargetGuard $guard): FixtureOnlyNetwork => new FixtureOnlyNetwork($guard));
        $this->app->extend(TargetResolver::class, static fn (TargetResolver $resolver): FixtureOnlyTargetResolver => new FixtureOnlyTargetResolver($resolver));
        $this->app->extend(DnsResolver::class, static fn (DnsResolver $resolver): FixtureOnlyDnsResolver => new FixtureOnlyDnsResolver($resolver));
        // Every Site create/URL change dispatches the icon fetch; on the sync test queue it would reach the network.
        Bus::fake([RefreshSiteIcon::class]);
        Http::preventStrayRequests();
        Http::allowStrayRequests(FixtureOnlyNetwork::allowedHttpUrls());
        // A running dev server must not make feature tests issue SSR HTTP requests.
        Vite::useHotFile(storage_path('framework/testing.hot'));
        config(['heptapod.health.browser_urls' => []]);
        $this->app->instance(DatabaseClock::class, new OffsetDatabaseClock(0));
        // The evaluator would otherwise read the host disk: a tight CI runner or an unreadable /backups opens a
        // disk_space_low issue that exists only there. Tests about disk space bind their own samples.
        $this->app->instance(DiskSpaceReader::class, FakeDiskSpaceReader::healthy());
        $this->app->instance(LiveConnectionTerminator::class, new FakeLiveConnectionTerminator);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
