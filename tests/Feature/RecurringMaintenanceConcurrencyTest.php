<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\DeleteRecurringMaintenancePeriod;
use Heptapod\Actions\UpdateRecurringMaintenancePeriod;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Models\Check;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('preserves a window activated while recurrence removal waits for its lock', function (string $action): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00Z'));
    config(['heptapod.maintenance.horizon_days' => 0]);
    $check = Check::factory()->create();
    $recurrence = RecurringMaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'timezone' => 'UTC']);
    $window = MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'recurring_maintenance_period_id' => $recurrence->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    // RefreshDatabase's transaction must be visible to the independent updater connection.
    DB::commit();
    $pid = DB::scalar('SELECT pg_backend_pid()');
    $updater = new Process([PHP_BINARY, '-r', <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse('2026-10-01 12:00:00Z'));
        $db = Illuminate\Support\Facades\DB::connection();
        $db->beginTransaction();
        $window = Heptapod\Models\MaintenancePeriod::query()->findOrFail((int) $argv[1]);
        app(Heptapod\Actions\UpdateMaintenancePeriod::class)($window, 'Activated', Carbon\CarbonImmutable::now()->subMinute(), Carbon\CarbonImmutable::now()->addHour());
        echo "locked\n";
        flush();
        $deadline = microtime(true) + 10;
        do {
            $waiting = $db->scalar("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock')", [(int) $argv[2]]);
            if ($waiting) {
                $db->commit();
                exit(0);
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Recurrence action never waited for the window lock.');
        PHP, (string) $window->id, (string) $pid], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')], timeout: 20);
    try {
        $updater->start();
        $updater->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked'));
        expect($updater->isRunning())->toBeTrue($updater->getErrorOutput());

        app($action)($recurrence, ...($action === UpdateRecurringMaintenancePeriod::class ? [['name' => 'Edited']] : []));
        $updater->wait();
        expect($updater->isSuccessful())->toBeTrue($updater->getErrorOutput());
        $this->assertModelExists($window);
        expect($window->fresh()->starts_at->toIso8601String())->toBe('2026-10-01T11:59:00+00:00');
        expect(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($check->id), CarbonImmutable::now())->suppressed)->toBeTrue();
    } finally {
        $updater->stop();
        // Restore the clean schema and transaction expected by RefreshDatabase teardown.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        DB::beginTransaction();
    }
})->with([UpdateRecurringMaintenancePeriod::class, DeleteRecurringMaintenancePeriod::class]);
