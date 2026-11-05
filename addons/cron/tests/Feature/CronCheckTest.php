<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Cron\Actions\UpdateCronTask;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Addons\Cron\CronTaskResult;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Queries\MonitorCountsReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OhDear\PhpSdk\Dto\CronCheckDefinition;

require_once __DIR__.'/../Pest.php';

function cronPilotContext(Check $check, int $runId = 101, ?string $claimToken = null): CheckContext
{
    return new CheckContext(
        $check->site_id, $check->id, $runId, $claimToken ?? (string) Str::uuid(), 'https://example.com',
        CarbonImmutable::now(), CarbonImmutable::now()->addSeconds(30), [], [], null,
    );
}

function cronPilotType(): CheckType
{
    return app(CheckRegistry::class)->get(CheckKey::Cron);
}

/** @param array<string, mixed> $settings */
function cronPilotTask(Check $check, array $settings = []): CronCheck
{
    return app(CronDefinitions::class)->create($check->id, [
        'name' => 'Scheduled backup', 'type' => 'simple', 'frequency_in_minutes' => 5,
        'grace_time_in_minutes' => 2, 'description' => 'Backup definition', ...$settings,
    ]);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:00:00Z'));
});

it('accepts Spatie starting, finished and failed HTTP pings with their real payload shape', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $ping = route('cron.ping', ['uuid' => $task->uuid]);
    expect(cronPilotType()->definition()->executionMode)->toBe(ExecutionMode::Push);
    expect(cronPilotType()->definition()->configSchema->validateValues([]))->toBe([]);
    expect($task->aggregate_result)->toBe(CheckResult::Pending);

    $this->postJson($ping.'/starting', [])->assertNoContent();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Pending);
    expect(cronPilotType()->execute(cronPilotContext($check)))->toBe(NoResultReason::CronTasksPending);
    expect(DB::table('cron_run_tasks')->count())->toBe(0);

    $this->travel(5)->minutes();
    $this->postJson($ping.'/finished', ['runtime' => 12.3, 'exit_code' => 0, 'memory' => 123456])->assertNoContent();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Succeeded);
    expect($task->latest_result)->toBe(CronTaskResult::Finished);
    expect($task->toArray()['latest_result'])->toBe('pingFinished');
    expect($task->getAttribute('ping_meta'))->toMatchArray(['runtime' => 12.3, 'exit_code' => 0, 'memory' => 123456]);
    expect($task->next_due_at->toIso8601String())->toBe('2026-10-01T10:10:00+00:00');

    $this->postJson($ping.'/failed', ['failure_message' => 'Process exited'])->assertNoContent();
    $context = cronPilotContext($check);
    $outcome = cronPilotType()->execute($context);
    expect($outcome->result)->toBe(RunResult::Failed);
    expect($outcome->events[0]->subject->componentType)->toBe(SuppressionComponent::Cron);
    expect($outcome->events[0]->subject->componentId)->toBe($task->id);
    expect($outcome->events[0]->subject->checkId)->toBe($check->id);
    expect($outcome->events[0]->payload['failure_message'])->toBe('Process exited');
    cronPilotType()->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect($task->refresh()->evaluated_result)->toBe(CheckResult::Failed);

    $this->postJson($ping.'/finished', ['exit_code' => 1])->assertNoContent();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Failed);
    $this->get($ping)->assertNoContent();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Succeeded);
    // An unevaluated failure that a later success overwrote still counts once (35ea9462); the next evaluation succeeds.
    $context = cronPilotContext($check, 102);
    expect(cronPilotType()->execute($context)->result)->toBe(RunResult::Failed);
    cronPilotType()->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect(cronPilotType()->execute(cronPilotContext($check, 103))->result)->toBe(RunResult::Succeeded);
    expect(DB::table('cron_pings')->where('cron_check_id', $task->id)->orderBy('id')->pluck('phase')->all())
        ->toBe(['starting', 'finished', 'failed', 'finished', 'finished']);
});

it('counts overdue pings at the grace boundary but not failed exit codes', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $counts = app(MonitorCountsReader::class);
    expect($counts->read($owner)->scheduledTasks)->toBe(1)
        ->and($counts->read($owner)->overduePings)->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:06:59.999999Z'));
    expect($counts->read($owner)->overduePings)->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:07:00Z'));
    expect($counts->read($owner)->overduePings)->toBe(1);
    $this->postJson(route('cron.ping', ['uuid' => $task->uuid, 'phase' => 'finished']), ['exit_code' => 1])->assertNoContent();
    expect($task->refresh()->latest_result)->toBe(CronTaskResult::Failed)
        ->and($counts->read($owner)->overduePings)->toBe(0);

    $missed = cronPilotTask($check, ['name' => 'Missed backup']);
    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:14:00Z'));
    $context = cronPilotContext($check);
    cronPilotType()->execute($context);
    cronPilotType()->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect($missed->refresh()->latest_result)->toBe(CronTaskResult::Missed)
        ->and($counts->read($owner)->scheduledTasks)->toBe(2)
        ->and($counts->read($owner)->overduePings)->toBe(1);
    $rescheduled = app(UpdateCronTask::class)($missed, ['frequency_in_minutes' => 60]);
    expect($rescheduled->next_due_at->isFuture())->toBeTrue()
        ->and($counts->read($owner)->overduePings)->toBe(1);
});

it('returns 404 for the public ping route when the cron addon store is unavailable', function (): void {
    app()->offsetUnset(CronTaskStore::class);
    $this->postJson(route('cron.ping', ['uuid' => (string) Str::uuid()]))->assertNotFound();
});

it('ignores missing pings during grace, warns on a late finish and fails at grace expiry for the specific task', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $sibling = cronPilotTask($check, ['name' => 'Hourly job', 'frequency_in_minutes' => 60]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:06:00Z'));
    expect(cronPilotType()->execute(cronPilotContext($check)))->toBe(NoResultReason::CronTasksPending);
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Pending);

    $this->postJson(route('cron.ping', ['uuid' => $task->uuid, 'phase' => 'finished']))->assertNoContent();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Warning);
    expect($task->late_by_seconds)->toBe(60);
    $context = cronPilotContext($check);
    $outcome = cronPilotType()->execute($context);
    expect($outcome->result)->toBe(RunResult::Warning);
    expect($outcome->events[0]->payload['late_by_seconds'])->toBe(60);
    cronPilotType()->definition()->metricSchema->validateValues($outcome->metrics->toArray());
    cronPilotType()->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);

    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:12:59.999999Z'));
    expect(cronPilotType()->execute(cronPilotContext($check, 102))->result)->toBe(RunResult::Warning);
    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:13:00Z'));
    $context = cronPilotContext($check, 103);
    $outcome = cronPilotType()->execute($context);
    expect($outcome->result)->toBe(RunResult::Failed);
    expect($outcome->metrics->toArray())->toBe(['succeeded' => 0, 'warning' => 0, 'failed' => 1, 'pending' => 1]);
    expect($outcome->events)->toHaveCount(1);
    expect($outcome->events[0]->subject->componentId)->toBe($task->id);
    expect($sibling->refresh()->aggregate_result)->toBe(CheckResult::Pending);
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Warning);
    cronPilotType()->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Failed);
});

it('coalesces a dispatcher waking days late and recovers when a finish finally arrives', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $this->travel(3)->days();
    $context = cronPilotContext($check);
    $outcome = cronPilotType()->execute($context);
    expect($outcome->result)->toBe(RunResult::Failed);
    expect($outcome->events)->toHaveCount(1);
    cronPilotType()->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    $this->travel(1)->hours();
    expect(cronPilotType()->execute(cronPilotContext($check, 102))->events)->toBe([]);

    $this->postJson(route('cron.ping', ['uuid' => $task->uuid, 'phase' => 'finished']))->assertNoContent();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Succeeded);
    expect($task->next_due_at->toIso8601String())->toBe('2026-10-04T11:05:00+00:00');
    $outcome = cronPilotType()->execute(cronPilotContext($check, 103));
    expect($outcome->result)->toBe(RunResult::Succeeded);
    expect($outcome->events[0]->payload['late_by_seconds'])->toBe(262500);
    expect($outcome->events[0]->payload['from_result'])->toBe('failed');
});

it('uses timezone-aware scheduled occurrences across DST rather than a fixed UTC interval', function (string $created, string $due, string $finished, string $next): void {
    $this->travelTo(CarbonImmutable::parse($created));
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = app(CronDefinitions::class)->create($check->id, [
        'name' => 'Nightly Berlin', 'type' => 'cron', 'cron_expression' => '30 2 * * *',
        'server_timezone' => 'Europe/Berlin', 'grace_time_in_minutes' => 5,
    ]);
    expect($task->next_due_at->toIso8601String())->toBe($due);
    $this->travelTo(CarbonImmutable::parse($due)->addMinutes(4));
    expect(cronPilotType()->execute(cronPilotContext($check)))->toBe(NoResultReason::CronTasksPending);
    $this->travelTo(CarbonImmutable::parse($due)->addMinutes(5));
    expect(cronPilotType()->execute(cronPilotContext($check, 102))->result)->toBe(RunResult::Failed);
    $this->travelTo(CarbonImmutable::parse($finished));
    $this->postJson(route('cron.ping', ['uuid' => $task->uuid, 'phase' => 'finished']))->assertNoContent();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Succeeded);
    expect($task->next_due_at->toIso8601String())->toBe($next);
})->with([
    'spring missing local hour shifts to next valid hour' => ['2026-03-28T23:00:00Z', '2026-03-29T01:30:00+00:00', '2026-03-29T01:40:00Z', '2026-03-30T00:30:00+00:00'],
    'fall repeated local hour is another occurrence' => ['2026-10-24T23:00:00Z', '2026-10-25T00:30:00+00:00', '2026-10-25T00:40:00Z', '2026-10-25T01:30:00+00:00'],
]);

it('fences task publication, preserves concurrent pings and rolls back publication with its transaction', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $this->travel(8)->minutes();
    $context = cronPilotContext($check);
    $other = cronPilotContext($check, 101);
    $type = cronPilotType();
    expect($type->execute($context)->result)->toBe(RunResult::Failed);
    expect($type->execute($other)->result)->toBe(RunResult::Failed);
    $type->persistence()->discard($context->runId, $other->claimToken);
    expect(DB::table('cron_run_tasks')->count())->toBe(1);
    $type->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), (string) Str::uuid());
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Pending);

    DB::beginTransaction();
    $type->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Failed);
    DB::rollBack();
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Pending);
    expect(DB::table('cron_run_tasks')->whereNotNull('run_ended_at')->count())->toBe(0);

    $this->postJson(route('cron.ping', ['uuid' => $task->uuid]))->assertNoContent();
    $type->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect($task->refresh()->aggregate_result)->toBe(CheckResult::Succeeded);
    $type->persistence()->discard($context->runId, $context->claimToken);
    expect(DB::table('cron_run_tasks')->whereNotNull('run_ended_at')->count())->toBe(1);
    expect(DB::table('cron_run_tasks')->value('result'))->toBe('failed');
});

it('rejects invalid definitions at their owning configuration boundary', function (array $settings, string $field): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    try {
        cronPilotTask($check, $settings);
        test()->fail('Expected invalid definition to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(CronCheck::query()->count())->toBe(0);
})->with([
    'zero frequency' => [['frequency_in_minutes' => 0], 'frequency_in_minutes'],
    'unknown definition field' => [['uuid' => (string) Str::uuid()], 'definition'],
    'wrong type' => [['type' => 'other'], 'type'],
    'invalid expression' => [['type' => 'cron', 'frequency_in_minutes' => null, 'cron_expression' => 'bad cron', 'server_timezone' => 'Europe/Berlin'], 'cron_expression'],
    'invalid timezone' => [['type' => 'cron', 'frequency_in_minutes' => null, 'cron_expression' => '* * * * *', 'server_timezone' => 'Not/AZone'], 'server_timezone'],
]);

it('rejects unknown tokens and phases and invalid payloads without changing a task', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $this->postJson(route('cron.ping', ['uuid' => (string) Str::uuid()]))->assertNotFound();
    $this->postJson('/cron/not-a-token')->assertNotFound();
    $this->postJson('/cron/'.$task->uuid.'/unknown')->assertNotFound();
    $this->postJson(route('cron.ping', ['uuid' => $task->uuid]), ['runtime' => -1])->assertUnprocessable()->assertJsonValidationErrors('runtime');
    expect(DB::table('cron_pings')->count())->toBe(0);
    expect($task->refresh()->ping_revision)->toBe(0);
    expect($task->aggregate_result)->toBe(CheckResult::Pending);
});

it('rejects foreign task datasets, unknown datasets and oversized batches', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $foreign = cronPilotTask(Check::factory()->create(['type' => CheckKey::Cron]));
    $context = cronPilotContext($check);
    $row = ['cron_check_id' => $foreign->id, 'result' => 'failed', 'ping_revision' => 0, 'failure_revision' => 0, 'failure_message' => null, 'late_by_seconds' => 0];
    expect(fn () => cronPilotType()->persistence()->stage($context, 'unknown', [$row]))->toThrow(InvalidArgumentException::class);
    expect(fn () => cronPilotType()->persistence()->stage($context, 'task_results', [$row]))->toThrow(InvalidArgumentException::class);
    expect(fn () => cronPilotType()->persistence()->stage($context, 'task_results', array_fill(0, 1001, $row)))->toThrow(InvalidArgumentException::class);
    expect(DB::table('cron_run_tasks')->count())->toBe(0);
});

it('emits one new failure report at unchanged severity without duplicating deadline evaluations', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $this->travel(8)->minutes();
    $type = cronPilotType();
    $context = cronPilotContext($check);
    expect($type->execute($context)->result)->toBe(RunResult::Failed);
    $type->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);

    $ping = route('cron.ping', ['uuid' => $task->uuid]);
    $this->postJson($ping.'/failed', ['failure_message' => 'Earlier backup error'])->assertNoContent();
    $this->postJson($ping.'/failed', ['failure_message' => 'New backup error'])->assertNoContent();
    $context = cronPilotContext($check, 102);
    $outcome = $type->execute($context);
    expect($outcome->result)->toBe(RunResult::Failed);
    expect($outcome->events)->toHaveCount(1);
    expect($outcome->events[0]->payload['failure_message'])->toBe('New backup error');
    expect($outcome->events[0]->payload['coalesced_count'])->toBe(2);
    expect(DB::table('cron_pings')->where('cron_check_id', $task->id)->orderBy('id')->get()->map(
        fn (object $ping): string => json_decode($ping->meta, true, flags: JSON_THROW_ON_ERROR)['failure_message'],
    )->all())->toBe(['Earlier backup error', 'New backup error']);
    expect($outcome->events[0]->subject->componentId)->toBe($task->id);
    $type->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect(DB::table('cron_run_tasks')->where('run_id', 102)->value('failure_message'))->toBe('New backup error');

    $this->postJson($ping.'/starting')->assertNoContent();
    $this->travel(1)->days();
    expect($type->execute(cronPilotContext($check, 103))->events)->toBe([]);
});

it('preserves nullable grace while evaluating the default and explicit zero at exact boundaries', function (?int $grace, string $expiry): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check, ['grace_time_in_minutes' => $grace]);
    expect($task->grace_time_in_minutes)->toBe($grace);
    $this->travelTo(CarbonImmutable::parse($expiry)->subMicrosecond());
    expect(cronPilotType()->execute(cronPilotContext($check)))->toBe(NoResultReason::CronTasksPending);
    $this->travelTo(CarbonImmutable::parse($expiry));
    expect(cronPilotType()->execute(cronPilotContext($check, 102))->result)->toBe(RunResult::Failed);
    expect($task->refresh()->grace_time_in_minutes)->toBe($grace);
})->with([
    'null uses the five-minute addon default' => [null, '2026-10-01T10:10:00Z'],
    'explicit zero has no grace' => [0, '2026-10-01T10:05:00Z'],
]);

it('advances the accepted failure cursor when starting arrives between evaluation and finalization', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $ping = route('cron.ping', ['uuid' => $task->uuid]);
    $this->postJson($ping.'/failed', ['failure_message' => 'Backup failed'])->assertNoContent();
    $context = cronPilotContext($check);
    $type = cronPilotType();
    expect($type->execute($context)->events)->toHaveCount(1);
    $this->postJson($ping.'/starting')->assertNoContent();
    $type->persistence()->finalize(new RunReference($context->runId, CarbonImmutable::now()), $context->claimToken);
    expect($task->refresh()->evaluated_failure_revision)->toBe(1);
    expect($type->execute(cronPilotContext($check, 102))->events)->toBe([]);
});

it('limits each task credential independently when multiple tasks share one IP', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $first = cronPilotTask($check);
    $second = cronPilotTask($check);
    $firstUrl = route('cron.ping', ['uuid' => $first->uuid]);
    for ($ping = 0; $ping < 120; $ping++) {
        $this->postJson($firstUrl)->assertNoContent();
    }
    $this->postJson($firstUrl)->assertTooManyRequests();
    $this->postJson(route('cron.ping', ['uuid' => $second->uuid]))->assertNoContent();
    expect($second->refresh()->ping_revision)->toBe(1);
});

it('preserves simple cron timezone and task results from the SDK-shaped fixture without translation', function (): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/cron-check.json'), true, flags: JSON_THROW_ON_ERROR);
    $definition = array_intersect_key($fixture, array_flip(['name', 'type', 'description', 'frequency_in_minutes', 'grace_time_in_minutes', 'cron_expression', 'server_timezone']));
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = app(CronDefinitions::class)->create($check->id, $definition);
    $this->postJson(route('cron.ping', ['uuid' => $task->uuid]))->assertNoContent();
    $stored = $task->refresh()->toArray();
    expect(array_intersect_key($stored, $definition))->toEqual($definition)
        ->and($stored['latest_result'])->toBe($fixture['latest_result'])
        ->and($stored['aggregate_result'])->toBe('succeeded')
        ->and($task->next_due_at->toIso8601String())->toBe('2026-10-01T10:15:00+00:00');
    $sdk = CronCheckDefinition::fromResponse([...$fixture, ...$stored]);
    expect($sdk->latestResult)->toBe('pingFinished')->and($sdk->serverTimezone)->toBe('Europe/Brussels');
});

it('prunes accepted history while retaining the last task baseline and stable identity', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = cronPilotTask($check);
    $this->postJson(route('cron.ping', ['uuid' => $task->uuid, 'phase' => 'failed']), ['failure_message' => 'Backup failed'])->assertNoContent();
    $type = cronPilotType();
    $credential = $task->uuid;
    foreach ([101, 102] as $runId) {
        $context = cronPilotContext($check, $runId);
        $type->execute($context);
        $type->persistence()->finalize(new RunReference($runId, CarbonImmutable::now()), $context->claimToken);
        $this->travel(1)->seconds();
    }
    $this->travel(61)->days();
    $type->persistence()->prune(CarbonImmutable::now()->subDays(31));
    expect(DB::table('cron_run_tasks')->pluck('run_id')->all())->toBe([102])
        ->and(DB::table('cron_pings')->count())->toBe(0)
        ->and($task->refresh()->uuid)->toBe($credential)
        ->and($task->latest_result)->toBe(CronTaskResult::Failed)
        ->and($task->ping_meta['failure_message'])->toBe('Backup failed');
    expect($type->execute(cronPilotContext($check, 103))->events)->toBe([]);
});
