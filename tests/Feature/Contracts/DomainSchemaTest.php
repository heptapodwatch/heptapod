<?php

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunRollupDaily;
use Heptapod\Models\CheckRunRollupHourly;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(RefreshDatabase::class);

it('creates every core model factory on Postgres and retains required relationships', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    foreach ([Site::class, Check::class, CheckRun::class, CheckSnooze::class, Incident::class, Event::class, CheckRunRollupHourly::class, CheckRunRollupDaily::class, DispatcherHeartbeat::class, Client::class, User::class] as $model) {
        expect($model::factory()->create()->exists)->toBeTrue();
    }
    $site = Site::factory()->create(['crawler_headers' => [['name' => 'Authorization', 'value' => 'crawler-secret']]]);
    $site->attachTag('Production');
    expect($site->fresh()->tags->first()->name)->toBe('Production');
    expect($site->toJson())->not->toContain('crawler-secret');
    expect(DB::table('sites')->where('id', $site->id)->value('crawler_headers'))->not->toContain('crawler-secret');
    expect(Check::factory()->create()->failure_threshold)->toBe(2);
    expect(Check::factory()->create(['type' => CheckKey::Performance])->failure_threshold)->toBe(1);
});

it('creates monthly partitions with composite primary key and routes sequence allocated runs', function (): void {
    $partitions = DB::select("SELECT child.relname FROM pg_inherits JOIN pg_class parent ON inhparent = parent.oid JOIN pg_class child ON inhrelid = child.oid WHERE parent.relname = 'check_runs'");
    $month = CarbonImmutable::now('UTC')->startOfMonth();
    $migrated = array_map(fn (int $offset): string => 'check_runs_'.$month->addMonths($offset)->format('Y_m'), [-1, 0, 1]);
    $epoch = array_map(fn (string $epochMonth): string => 'check_runs_'.str_replace('-', '_', $epochMonth), TestCase::CheckRunPartitionEpochMonths);
    $names = array_column($partitions, 'relname');
    expect($names)->toContain(...$migrated)
        ->and(array_values(array_diff($names, $migrated, $epoch)))->toBe([]);
    $pk = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'check_runs'::regclass AND contype = 'p'");
    expect($pk->definition)->toBe('PRIMARY KEY (id, ended_at)');
    $runs = [];
    foreach ([-1, 0, 1] as $offset) {
        $ended = $month->addMonths($offset)->addDay();
        $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended]);
        $runs[] = $run->id;
        $table = DB::selectOne('SELECT tableoid::regclass::text AS name FROM check_runs WHERE id = ? AND ended_at = ?', [$run->id, $ended]);
        expect($table->name)->toBe('check_runs_'.$ended->format('Y_m'));
    }
    expect(array_unique($runs))->toHaveCount(3);
});

it('scopes run updates and deletes by physical identity even if an explicit import duplicates an id', function (): void {
    $ended = CarbonImmutable::now('UTC')->startOfSecond()->addMicroseconds(123456);
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended]);
    $other = CheckRun::factory()->create(['id' => $run->id, 'started_at' => $ended->subSecond(), 'ended_at' => $ended->addMicrosecond()]);
    expect($run->fresh()->ended_at->format('u'))->toBe('123456');
    $run->update(['summary' => 'changed']);
    expect($other->fresh()->summary)->not->toBe('changed');
    $run->delete();
    expect(CheckRun::query()->forRun($other->reference())->exists())->toBeTrue();
});

it('retains incident evidence and latest snapshot pointers after a raw partition is dropped', function (): void {
    $ended = CarbonImmutable::now('UTC')->startOfMonth()->subMonth()->addDay();
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended, 'evidence' => ['status_code' => 503]]);
    $run->check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $ended]);
    $incident = Incident::factory()->create(['check_id' => $run->check_id, 'evidence' => $run->evidence]);
    DB::statement('DROP TABLE check_runs_'.$ended->format('Y_m'));
    expect($incident->fresh()->evidence)->toBe(['status_code' => 503]);
    expect($run->check->fresh()->latest_run_id)->toBe($run->id);
});

it('keeps empty restricted Member client scope restricted', function (): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $client = Client::factory()->create();
    $user->clients()->attach($client);
    expect($user->canAccessClient($client->id))->toBeTrue();
    $user->clients()->detach();
    expect($user->fresh()->canAccessClient($client->id))->toBeFalse();
    $user->update(['client_scope' => ClientScope::All]);
    expect($user->canAccessClient($client->id))->toBeTrue();
});

it('protects the seeded internal client at the database boundary', function (): void {
    $internal = Client::query()->where('is_internal', true)->sole();
    expect(fn () => $internal->delete())->toThrow(QueryException::class);
});

it('evaluates snoozes at event time and keeps unsnooze history', function (): void {
    $at = CarbonImmutable::now('UTC')->startOfSecond()->addMicroseconds(500000)->setTimezone('Europe/Berlin');
    $snooze = CheckSnooze::factory()->create(['starts_at' => $at, 'ends_at' => $at->addHour()]);
    $service = app(NotificationSuppression::class);
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at->subMicrosecond())->suppressed)->toBeFalse();
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at)->reason)->toBe('snooze');
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at->addHour()->subMicrosecond())->suppressed)->toBeTrue();
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at->addHour())->suppressed)->toBeFalse();
    $snooze->update(['unsnoozed_at' => $at->addMinute(), 'unsnoozed_by' => 'Operator']);
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at)->suppressed)->toBeTrue();
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at->addMinute()->subMicrosecond())->suppressed)->toBeTrue();
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at->addMinute())->suppressed)->toBeFalse();
    expect(CheckSnooze::query()->count())->toBe(1);
});

it('distinguishes component snoozes while whole-check snoozes cover every component', function (SuppressionComponent $type): void {
    $at = CarbonImmutable::now('UTC')->startOfSecond()->addMicroseconds(500000)->setTimezone('Europe/Berlin');
    $snooze = CheckSnooze::factory()->create([
        'component_type' => $type, 'component_id' => 10, 'starts_at' => $at, 'ends_at' => $at->addMinute(),
    ])->refresh();
    $service = app(NotificationSuppression::class);
    $subject = new SuppressionSubject($snooze->check_id, $type, 10);
    $otherType = $type === SuppressionComponent::Cron ? SuppressionComponent::ApplicationHealth : SuppressionComponent::Cron;
    expect($service->isSuppressed($subject, $at->subMicrosecond())->suppressed)->toBeFalse();
    expect($service->isSuppressed($subject, $at)->reason)->toBe('snooze');
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id, $type, 11), $at)->suppressed)->toBeFalse();
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id, $otherType, 10), $at)->suppressed)->toBeFalse();
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id), $at)->suppressed)->toBeFalse();
    expect($service->isSuppressed(new SuppressionSubject(Check::factory()->create()->id, $type, 10), $at)->suppressed)->toBeFalse();
    expect($service->isSuppressed($subject, $at->addMinute()->subMicrosecond())->suppressed)->toBeTrue();
    expect($service->isSuppressed($subject, $at->addMinute())->suppressed)->toBeFalse();
    $snooze->update(['unsnoozed_at' => $at->addSecond(), 'unsnoozed_by' => 'Operator']);
    expect($service->isSuppressed($subject, $at->addSecond()->subMicrosecond())->suppressed)->toBeTrue();
    expect($service->isSuppressed($subject, $at->addSecond())->suppressed)->toBeFalse();
    CheckSnooze::factory()->create(['check_id' => $snooze->check_id, 'starts_at' => $at, 'ends_at' => $at->addMinute()]);
    expect($service->isSuppressed($subject, $at->addSecond())->suppressed)->toBeTrue();
    expect($service->isSuppressed(new SuppressionSubject($snooze->check_id, $type, 11), $at)->suppressed)->toBeTrue();
})->with(SuppressionComponent::cases());

it('rejects incomplete or invalid snooze subjects in the core store', function (array $subject): void {
    $snooze = CheckSnooze::factory()->create();
    expect(fn () => DB::table('check_snoozes')->where('id', $snooze->id)->update($subject))->toThrow(QueryException::class);
})->with([
    [['component_type' => 'cron', 'component_id' => null]],
    [['component_type' => null, 'component_id' => 10]],
    [['component_type' => 'cron', 'component_id' => 0]],
    [['component_type' => 'dns', 'component_id' => 10]],
]);

it('rejects duplicate site check identities', function (): void {
    $check = Check::factory()->create();
    expect(fn () => Check::factory()->create(['site_id' => $check->site_id]))->toThrow(QueryException::class);
});

it('keeps error runs without metrics distinct from metric samples', function (): void {
    $check = Check::factory()->create();
    $error = CheckRun::factory()->create(['check_id' => $check->id, 'result' => RunResult::Errored, 'metrics' => null, 'error' => '{"class":"RuntimeException","message":"Probe deadline exceeded"}'])->refresh();
    $sample = CheckRun::factory()->create(['check_id' => $check->id, 'metrics' => ['total_time_in_seconds' => 1.2]])->refresh();
    expect($error->metrics)->toBeNull();
    expect($sample->metrics)->toBe(['total_time_in_seconds' => 1.2]);
});

it('round trips a retained run reference without losing its physical microsecond identity', function (): void {
    $ended = CarbonImmutable::now('UTC')->startOfSecond()->addMicroseconds(123456)->setTimezone('Europe/Berlin');
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended])->refresh();
    $json = json_decode($run->reference()->toJson(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['endedAt'])->toBe($ended->utc()->format('Y-m-d\TH:i:s.uP'));
    $reference = RunReference::from($json);
    expect(CheckRun::query()->forRun($reference)->sole()->id)->toBe($run->id);
});

it('persists exactly the eleven supported check identities', function (): void {
    $site = Site::factory()->create();
    foreach (CheckKey::cases() as $type) {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => $type])->refresh();
        expect($check->type)->toBe($type);
    }
    expect($site->checks()->count())->toBe(11);
});

it('cannot persist a successful run without metric observations', function (): void {
    expect(fn () => CheckRun::factory()->create(['result' => RunResult::Succeeded, 'metrics' => null]))->toThrow(QueryException::class);
});
