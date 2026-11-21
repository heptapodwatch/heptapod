<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\MarkBackupRunning;
use Heptapod\Actions\ReconcileQueuedWork;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Jobs\RunBackupNow;
use Heptapod\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

it('queues one Owner backup and reports the observed result without exception contents', function (): void {
    Storage::fake('backups');
    Queue::fake([RunBackupNow::class]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $id = $this->actingAs($owner)->postJson(route('settings.backups.store'))->assertAccepted()->json('id');
    Queue::assertPushed(RunBackupNow::class, fn (RunBackupNow $job): bool => $job->backupId === $id);
    expect(DB::table('backup_runs')->where('id', $id)->value('state'))->toBe('queued');
    $this->getJson(route('settings.backups.data'))->assertOk()->assertJsonPath('runs.0.state', 'queued');
    $this->travel(6)->minutes();
    $this->postJson(route('settings.backups.store'))->assertConflict();
    expect(DB::table('backup_runs')->count())->toBe(1);
    app(MarkBackupRunning::class)($id);
    Context::scope(static fn (): mixed => event(new BackupHasFailed(new RuntimeException('private credential leak', 403), 'backups', 'Heptapod')),
        ['backup-run-id' => $id]);
    $response = $this->getJson(route('settings.backups.data'))->assertOk()->assertJsonPath('runs.0.state', 'failed')
        ->assertJsonPath('runs.0.http_status', 403);
    expect($response->getContent())->not->toContain('private credential leak');
    $this->postJson(route('settings.backups.store'))->assertTooManyRequests();
});

it('releases a backup slot on worker failure and after an abandoned running timeout', function (): void {
    Queue::fake([RunBackupNow::class]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $first = $this->actingAs($owner)->postJson(route('settings.backups.store'))->assertAccepted()->json('id');
    app(MarkBackupRunning::class)($first);
    new RunBackupNow($first)->failed(new RuntimeException('worker exhausted'));
    new RunBackupNow($first)->failed(new RuntimeException('duplicate failure'));
    expect(DB::table('backup_runs')->where('id', $first)->value('state'))->toBe('failed');
    $this->travel(6)->minutes();
    $second = $this->postJson(route('settings.backups.store'))->assertAccepted()->json('id');
    app(MarkBackupRunning::class)($second);
    DB::table('backup_runs')->where('id', $second)->update(['started_at' => now()->subSeconds(1261)]);
    $this->travel(6)->minutes();
    $this->postJson(route('settings.backups.store'))->assertAccepted();
    expect(DB::table('backup_runs')->where('id', $second)->value('state'))->toBe('failed');
});

it('records scheduled backup results with UTC timestamps and rejects non-Owners from history and trigger', function (string $role): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    Storage::fake('backups');
    event(new BackupWasSuccessful('backups', 'Heptapod'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole($role);
    $this->getJson(route('settings.backups.data'))->assertUnauthorized();
    $this->actingAs($member)->getJson(route('settings.backups.data'))->assertForbidden();
    $this->postJson(route('settings.backups.store'))->assertForbidden();
    $this->actingAs($owner)->getJson(route('settings.backups.data'))->assertOk()->assertJsonPath('runs.0.state', 'complete')
        ->assertJsonPath('runs.0.requested_by', null)
        ->assertJsonPath('runs.0.requested_at', '2026-10-05T00:30:00+00:00')
        ->assertJsonPath('runs.0.completed_at', '2026-10-05T00:30:00+00:00');
})->with(['Member', 'Admin']);

it('does not leave a manual backup stuck in queued after a dispatch failure or a lost queued job', function (): void {
    $owner = User::factory()->owner()->create();
    Queue::addConnector('failing-backup-redis', static fn (): ConnectorInterface => new class implements ConnectorInterface
    {
        public function connect(array $config): Illuminate\Contracts\Queue\Queue
        {
            return new class extends NullQueue
            {
                public function push($job, $data = '', $queue = null): mixed
                {
                    throw new RuntimeException('Redis dispatch unavailable');
                }
            };
        }
    });
    config(['queue.connections.redis.driver' => 'failing-backup-redis']);

    $this->actingAs($owner)->postJson(route('settings.backups.store'))->assertServerError();
    $first = DB::table('backup_runs')->sole();
    expect($first->state)->toBe('failed')
        ->and($first->completed_at)->not->toBeNull();

    // Once the queue recovers, a new manual backup is accepted instead of returning 409 forever.
    Queue::fake([RunBackupNow::class]);
    $this->travel(6)->minutes();
    $second = $this->actingAs($owner)->postJson(route('settings.backups.store'))->assertAccepted()->json('id');

    // A lost queued job expires after the queued timeout and unblocks future manual backups.
    DB::table('backup_runs')->where('id', $second)->update(['requested_at' => now('UTC')->subSeconds(ExecutionBudget::RetryAfter + 1)]);
    $this->travel(6)->minutes();
    $third = $this->actingAs($owner)->postJson(route('settings.backups.store'))->assertAccepted()->json('id');

    expect(DB::table('backup_runs')->where('id', $second)->value('state'))->toBe('failed')
        ->and(DB::table('backup_runs')->where('id', $third)->value('state'))->toBe('queued');
});

it('expires stale backups conditionally in one write so a freshly claimed backup is never failed', function (): void {
    $owner = User::factory()->owner()->create();
    $staleId = DB::table('backup_runs')->insertGetId([
        'requested_by' => $owner->id,
        'state' => 'queued',
        'requested_at' => now('UTC')->subSeconds(ExecutionBudget::RetryAfter + 5),
    ]);
    $claimedId = DB::table('backup_runs')->insertGetId([
        'requested_by' => $owner->id,
        'state' => 'queued',
        'requested_at' => now('UTC')->subSeconds(ExecutionBudget::RetryAfter + 5),
    ]);
    app(MarkBackupRunning::class)($claimedId);

    $sawUpdate = false;
    $claimedDuringSelect = false;
    DB::listen(static function (QueryExecuted $query) use (&$sawUpdate, &$claimedDuringSelect, $staleId): void {
        if (! str_contains($query->sql, '"backup_runs"')) {
            return;
        }
        if (str_starts_with(strtolower($query->sql), 'update')) {
            $sawUpdate = true;
        } elseif (! $sawUpdate && ! $claimedDuringSelect && str_starts_with(strtolower($query->sql), 'select')) {
            $claimedDuringSelect = true;
            app(MarkBackupRunning::class)($staleId);
        }
    });

    app(ReconcileQueuedWork::class)->failStaleBackups();

    expect($claimedDuringSelect)->toBeFalse()
        ->and($sawUpdate)->toBeTrue()
        ->and(DB::table('backup_runs')->where('id', $staleId)->value('state'))->toBe('failed')
        ->and(DB::table('backup_runs')->where('id', $claimedId)->value('state'))->toBe('running');
});
