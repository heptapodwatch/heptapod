<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    Queue::fake();
});

it('coalesces downtime to the first strictly future per-check slot', function (string $at, int $lateBy, string $nextSlot): void {
    $this->travelTo(CarbonImmutable::parse($at));
    $check = Check::factory()->create(['id' => 600, 'interval_seconds' => 60, 'next_run_at' => now()->subSeconds($lateBy)]);

    expect(app(ClaimService::class)->dispatchDue())->toBe(1);
    expect($check->refresh()->next_run_at->toIso8601String())->toBe($nextSlot);
    $claimed = $check->refresh();
    expect($claimed->claim_token)->not->toBeNull()
        ->and($claimed->lease_expires_at->diffInSeconds($claimed->dispatched_at, true))->toBe((float) ExecutionBudget::QueuedStaleTimeout)
        ->and(app(ClaimService::class)->dispatchDue())->toBe(0);
})->with([
    'due now' => ['2026-10-28T12:00:00Z', 0, '2026-10-28T12:00:35+00:00'],
    'one second late' => ['2026-10-28T12:00:00Z', 1, '2026-10-28T12:00:35+00:00'],
    'three days late' => ['2026-10-28T12:00:00Z', 259211, '2026-10-28T12:00:35+00:00'],
    'exactly on its slot' => ['2026-10-28T12:00:35Z', 0, '2026-10-28T12:01:35+00:00'],
]);

it('bounds batches and rejects inactive sites and live leases', function (): void {
    $enabled = Check::factory()->count(3)->create(['next_run_at' => now()->subMinute()]);
    $disabled = Check::factory()->create(['enabled' => false]);
    $paused = Check::factory()->for(Site::factory()->state(['paused_at' => now()]))->create();
    $deleting = Check::factory()->for(Site::factory()->state(['marked_for_deletion_at' => now()]))->create();
    $live = Check::factory()->create(['claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(5)]);

    expect(app(ClaimService::class)->dispatchDue(2))->toBe(2)
        ->and(app(ClaimService::class)->dispatchDue(2))->toBe(1);
    foreach ($enabled as $check) {
        expect($check->refresh()->claim_token)->not->toBeNull();
    }
    foreach ([$disabled, $paused, $deleting] as $check) {
        expect($check->refresh()->claim_token)->toBeNull();
    }
    expect($live->refresh()->dispatched_at)->toBeNull();
});

it('reclaims an expired lost job without waiting for or moving its future cadence', function (): void {
    $oldToken = (string) Str::uuid();
    $check = Check::factory()->create([
        'type' => CheckKey::Performance, 'next_run_at' => now()->addDay(), 'claim_token' => $oldToken,
        'lease_expires_at' => now()->subSecond(), 'run_started_at' => now()->subMinutes(5), 'active_run_id' => 42,
    ]);
    $due = $check->next_run_at;

    expect(app(ClaimService::class)->dispatchDue())->toBe(1);
    expect($check->refresh()->claim_token)->not->toBe($oldToken)
        ->and($check->next_run_at->equalTo($due))->toBeTrue()
        ->and($check->run_started_at)->toBeNull()
        ->and($check->active_run_id)->toBeNull();
});

it('uses the same lease for manual requests and preserves future scheduled cadence', function (): void {
    $check = Check::factory()->create(['next_run_at' => now()->addHour()]);
    $due = $check->next_run_at;
    $claims = app(ClaimService::class);

    expect($claims->runNow($check->id, RunOrigin::System))->not->toBeNull()
        ->and($claims->runNow($check->id, RunOrigin::System))->toBeNull()
        ->and($check->refresh()->manual_run)->toBeTrue()
        ->and($check->next_run_at->equalTo($due))->toBeTrue();
    $check->site->update(['paused_at' => now()]);
    $check->update(['lease_expires_at' => now()->subSecond()]);
    expect($claims->runNow($check->id, RunOrigin::System))->toBeNull();
    $check->site->update(['paused_at' => null]);
    $check->update(['enabled' => false]);
    expect($claims->runNow($check->id, RunOrigin::System))->toBeNull()
        ->and($claims->runNow(PHP_INT_MAX, RunOrigin::System))->toBeNull();
    $claims->revoke($check);
    expect($check->refresh()->manual_run)->toBeFalse();
    $check->update(['enabled' => true, 'next_run_at' => now()->subSecond()]);
    expect($claims->dispatchDue())->toBe(1)
        ->and($check->refresh()->manual_run)->toBeFalse();
});

it('writes a heartbeat through the single-tick command and rolls back uncommitted claims', function (): void {
    $check = Check::factory()->create(['next_run_at' => now()->subMinute()]);
    DB::beginTransaction();
    app(ClaimService::class)->dispatchDue();
    DB::rollBack();
    expect($check->refresh()->claim_token)->toBeNull();
    Queue::assertNotPushed(RunCheck::class);

    $this->artisan('heptapod:dispatch', ['--once' => true])->assertSuccessful();
    expect(DB::table('dispatcher_heartbeats')->where('id', gethostname().':'.getmypid())->exists())->toBeTrue()
        ->and($check->refresh()->claim_token)->not->toBeNull();
});
