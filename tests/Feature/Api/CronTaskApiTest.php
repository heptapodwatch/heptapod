<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\RequestCheckRun;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    app()->singleton(ApiSpec::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('Owner');
    $this->withToken($this->owner->createToken('cron', ['*'])->plainTextToken);
    $this->site = Site::factory()->create();
    $this->url = '/api/monitors/'.$this->site->id.'/cron-checks';
    $this->definition = ['name' => 'schedule', 'type' => 'cron', 'cron_expression' => '* * * * *', 'server_timezone' => 'Europe/Berlin', 'grace_time_in_minutes' => 5, 'description' => ''];
});

it('manages cron definitions through every spec operation and preserves UUID during sync', function (): void {
    $this->getJson($this->url)->assertOk()->assertExactJson(['data' => []]);
    $this->postJson($this->url.'/sync', ['cron_checks' => []])->assertOk()->assertExactJson([]);
    expect($this->site->checks()->count())->toBe(0);
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->assertJsonPath('server_timezone', 'Europe/Berlin')->json();
    $check = $this->site->checks()->sole();
    expect($check->type)->toBe(CheckKey::Cron)->and($check->enabled)->toBeTrue();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.id', $task['id']);
    $this->putJson('/api/cron-checks/'.$task['id'], ['cron_expression' => '0 * * * *'])->assertOk()->assertJsonPath('uuid', $task['uuid']);
    $this->postJson('/api/cron-checks/'.$task['id'].'/snooze', ['minutes' => '10'])->assertOk()->assertJsonPath('active_snooze.snoozed_by', $this->owner->name);
    $this->postJson('/api/cron-checks/'.$task['id'].'/unsnooze')->assertOk()->assertJsonPath('active_snooze', null);
    $this->postJson($this->url.'/sync', ['cron_checks' => [$this->definition]])->assertOk()->assertJsonPath('0.uuid', $task['uuid'])->assertJsonPath('0.cron_expression', '* * * * *');
    $this->deleteJson('/api/cron-checks/'.$task['id'])->assertNoContent();
    $this->getJson($this->url)->assertOk()->assertExactJson(['data' => []]);
    expect(array_keys(app(ApiSpec::class)->validated))->toContain('listCronChecks', 'createCronCheck', 'updateCronCheck', 'deleteCronCheck', 'syncCronChecks', 'snoozeCronCheck', 'unsnoozeCronCheck');
});

it('validates the entire sync before deleting and supports partial updates and type changes', function (): void {
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $this->postJson($this->url.'/sync', ['cron_checks' => [array_replace($this->definition, ['name' => 'bad', 'cron_expression' => 'broken'])]])->assertUnprocessable();
    $this->postJson($this->url.'/sync', ['cron_checks' => [$this->definition, $this->definition]])->assertUnprocessable();
    $this->putJson('/api/cron-checks/'.$task['id'], ['type' => 'simple', 'frequency_in_minutes' => 15])->assertOk()->assertJsonPath('cron_expression', null)->assertJsonPath('frequency_in_minutes', 15);
    $this->putJson('/api/cron-checks/'.$task['id'], ['frequency_in_minutes' => 0])->assertUnprocessable();
    $this->postJson($task['ping_url'])->assertNoContent();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.latest_result', 'pingFinished');
    $this->postJson($this->url.'/sync', ['cron_checks' => []])->assertOk()->assertExactJson([]);
    expect($this->site->checks()->sole()->enabled)->toBeTrue();
    $this->postJson($task['ping_url'])->assertNotFound();
});

it('enforces independent abilities while Admin sees all task IDs', function (): void {
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $read = $this->owner->createToken('read', ['checks:read'])->plainTextToken;
    $this->withToken($read)->getJson($this->url)->assertOk();
    $this->postJson($this->url, $this->definition)->assertForbidden();
    $this->postJson('/api/cron-checks/'.$task['id'].'/snooze', ['minutes' => 5])->assertForbidden();
    $this->withToken($this->owner->createToken('snooze', ['checks:snooze'])->plainTextToken);
    $this->postJson('/api/cron-checks/'.$task['id'].'/snooze', ['minutes' => 5])->assertOk();
    $this->deleteJson('/api/cron-checks/'.$task['id'])->assertForbidden();
    $client = Client::factory()->create();
    $restricted = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $restricted->assignRole('Admin');
    $restricted->clients()->attach($client);
    $adminToken = $restricted->createToken('restricted', ['*'])->plainTextToken;
    $this->withToken($adminToken);
    $this->getJson($this->url)->assertOk();
    $this->postJson($this->url.'/sync', ['cron_checks' => [$this->definition]])->assertOk();
    $this->putJson('/api/cron-checks/'.$task['id'], ['name' => 'visible'])->assertOk();
    $this->postJson('/api/cron-checks/'.$task['id'].'/snooze', ['minutes' => 5])->assertOk();
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->withToken($member->createToken('member', ['checks:read'])->plainTextToken);
    $masked = $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.uuid', '********')->assertDontSee($task['uuid'])->json('data.0.ping_url');
    expect($masked)->toContain('00000000-0000-0000-0000-000000000000');
    $this->postJson($masked)->assertNotFound();
    $this->withToken($adminToken)->deleteJson('/api/cron-checks/'.$task['id'])->assertNoContent();
    $this->withToken($member->createToken('member-write', ['*'])->plainTextToken);
    $this->postJson($this->url, $this->definition)->assertForbidden();
    $this->postJson($this->url.'/sync', ['cron_checks' => [$this->definition]])->assertForbidden();
});

it('returns not found for an uninstalled cron addon without resolving the unbound store', function (): void {
    app()->instance(CheckRegistry::class, new CheckRegistry(app()));
    app()->offsetUnset(CronTaskStore::class);
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url, $this->definition)->assertNotFound();
    $this->postJson($this->url.'/sync', ['cron_checks' => []])->assertNotFound();
    $this->postJson('/api/cron-checks/1/snooze', ['minutes' => 5])->assertNotFound();
});

it('resolves a failed parent through the engine after empty sync even during an active claim', function (string $locale, string $message): void {
    $this->owner->update(['locale' => $locale]);
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $this->postJson($task['ping_url'].'/failed')->assertNoContent();
    $check = $this->site->checks()->sole();
    app(RequestCheckRun::class)($check, RunOrigin::System);
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
    $incident = $check->incidents()->sole();
    expect($incident->resolved_at)->toBeNull();
    app(RequestCheckRun::class)($check, RunOrigin::System);
    $claim = $check->refresh()->claim_token;
    $this->postJson($this->url.'/sync', ['cron_checks' => []])->assertOk();
    expect($check->refresh()->claim_token)->toBe($claim);
    app(CheckExecutor::class)->execute($check->id, $claim, 30);
    expect($check->refresh()->summary)->toBe('cron.no_tasks')->and($check->latest_run_result)->toBe(CheckResult::Succeeded);
    expect($incident->refresh()->resolved_at)->not->toBeNull();
    $this->actingAs($this->owner)->get('/sites/'.$this->site->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('events', fn (Collection $events): bool => $events->contains(fn (array $event): bool => $event['type'] === 'incident.resolved'
            && $event['message'] === $message && $event['duration_seconds'] === null)));
})->with([
    ['en', 'Resolved — no scheduled tasks'],
    ['de', 'Behoben — keine geplanten Aufgaben'],
]);

it('records a no tasks outcome without an incident for an empty fresh parent', function (): void {
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $this->deleteJson('/api/cron-checks/'.$task['id'])->assertNoContent();
    $check = $this->site->checks()->sole();
    expect($check->claim_token)->not->toBeNull();
    app(CheckExecutor::class)->execute($check->id, $check->claim_token, 30);
    expect($check->refresh()->summary)->toBe('cron.no_tasks')->and($check->latest_run_result)->toBe(CheckResult::Succeeded);
    expect($check->incidents()->count())->toBe(0);
});

it('keeps distinct numeric string task identities across repeated sync', function (): void {
    $definitions = [array_replace($this->definition, ['name' => '1']), array_replace($this->definition, ['name' => '01'])];
    $first = $this->postJson($this->url.'/sync', ['cron_checks' => $definitions])->assertOk()->json();
    $second = $this->postJson($this->url.'/sync', ['cron_checks' => $definitions])->assertOk()->json();
    expect(array_column($second, 'id'))->toBe(array_column($first, 'id'));
    expect(array_column($second, 'uuid'))->toBe(array_column($first, 'uuid'));
    $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data');
});

it('discards a cron evaluation deleted between reading and staging or between staging and acceptance', function (string $barrier): void {
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $this->postJson($task['ping_url'].'/failed')->assertNoContent();
    $check = $this->site->checks()->sole();
    app(RequestCheckRun::class)($check, RunOrigin::System);
    $deleted = false;
    $delete = function () use (&$deleted, $task): void {
        if (! $deleted) {
            $deleted = true;
            $this->deleteJson('/api/cron-checks/'.$task['id'])->assertNoContent();
        }
    };
    if ($barrier === 'read') {
        Illuminate\Support\Facades\Event::listen('eloquent.retrieved: '.CronCheck::class, $delete);
    } else {
        DB::listen(function (QueryExecuted $query) use ($delete): void {
            if (str_starts_with($query->sql, 'insert into "cron_run_tasks"')) {
                $delete();
            }
        });
    }
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
    expect($deleted)->toBeTrue();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Pending);
    expect($check->claim_token)->toBeNull();
    expect($check->incidents()->count())->toBe(0);
    expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(0);
    expect(Event::query()->where('check_id', $check->id)->count())->toBe(0);
    expect(app(ClaimService::class)->dispatchDue())->toBe(0);
    expect($check->next_run_at->getTimestamp())->toBeGreaterThan(now()->addMinute()->getTimestamp())
        ->toBeLessThanOrEqual(now()->addMinutes(2)->getTimestamp());
    DB::table('checks')->where('id', $check->id)->update(['next_run_at' => now()]);
    expect(app(ClaimService::class)->dispatchDue())->toBe(1);
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
    expect($check->refresh()->summary)->toBe('cron.no_tasks');
    expect($check->latest_run_result)->toBe(CheckResult::Succeeded);
})->with(['read', 'staged']);

it('resolves an existing incident on the next interval after a requested evaluation is invalidated by empty sync', function (): void {
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $this->postJson($task['ping_url'].'/failed')->assertNoContent();
    $check = $this->site->checks()->sole();
    app(RequestCheckRun::class)($check, RunOrigin::System);
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
    $incident = $check->incidents()->sole();
    app(RequestCheckRun::class)($check, RunOrigin::System);
    $synced = false;
    DB::listen(function (QueryExecuted $query) use (&$synced): void {
        if (! $synced && str_starts_with($query->sql, 'insert into "cron_run_tasks"')) {
            $synced = true;
            $this->postJson($this->url.'/sync', ['cron_checks' => []])->assertOk();
        }
    });
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
    expect($synced)->toBeTrue();
    expect($incident->refresh()->resolved_at)->toBeNull();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed);
    expect($check->claim_token)->toBeNull();
    expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1);
    expect(Event::query()->where('check_id', $check->id)->where('type', 'incident.resolved')->count())->toBe(0);
    expect(app(ClaimService::class)->dispatchDue())->toBe(0);
    DB::table('checks')->where('id', $check->id)->update(['next_run_at' => now()]);
    expect(app(ClaimService::class)->dispatchDue())->toBe(1);
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
    expect($check->refresh()->summary)->toBe('cron.no_tasks');
    expect($incident->refresh()->resolved_at)->not->toBeNull();
    expect(Event::query()->where('check_id', $check->id)->where('type', 'incident.resolved')->count())->toBe(1);
});

it('publishes a failed ping despite continuous irrelevant sync', function (bool $descriptive): void {
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $this->postJson($task['ping_url'].'/failed')->assertNoContent();
    $check = $this->site->checks()->sole();
    $inside = false;
    $changes = 0;
    Illuminate\Support\Facades\Event::listen('eloquent.retrieved: '.CronCheck::class, function () use (&$inside, &$changes, $descriptive): void {
        if ($inside) {
            return;
        }
        $inside = true;
        $changes++;
        $this->postJson($this->url.'/sync', ['cron_checks' => [array_replace($this->definition, $descriptive ? ['description' => 'revision '.$changes] : [])]])->assertOk();
        $inside = false;
    });
    foreach ([1, 2] as $attempt) {
        app(RequestCheckRun::class)($check, RunOrigin::System);
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
        expect($changes)->toBeGreaterThanOrEqual($attempt);
        expect($check->refresh()->claim_token)->toBeNull();
        expect($check->latest_run_result)->toBe(CheckResult::Failed);
        expect(app(ClaimService::class)->dispatchDue())->toBe(0);
    }
    expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(2);
    expect($check->incidents()->count())->toBe(1);
})->with(['identical' => false, 'description only' => true]);

it('publishes a failure after at most one discard while relevant sync waits for the serialized retry', function (): void {
    $task = $this->postJson($this->url, $this->definition)->assertCreated()->json();
    $this->postJson($task['ping_url'].'/failed')->assertNoContent();
    $check = $this->site->checks()->sole();
    $changed = false;
    $event = 'eloquent.retrieved: '.CronCheck::class;
    Illuminate\Support\Facades\Event::listen($event, function () use (&$changed): void {
        if (! $changed) {
            $changed = true;
            $this->definition['grace_time_in_minutes']++;
            $this->postJson($this->url.'/sync', ['cron_checks' => [$this->definition]])->assertOk();
        }
    });
    app(RequestCheckRun::class)($check, RunOrigin::System);
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Pending);
    expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(0);
    Illuminate\Support\Facades\Event::forget($event);
    $token = $this->owner->createToken('concurrent-sync', ['*'])->plainTextToken;
    $worker = new Process([PHP_BINARY, __DIR__.'/Fixtures/cron-sync-process.php', (string) $this->site->id, $token,
        json_encode(['cron_checks' => [array_replace($this->definition, ['grace_time_in_minutes' => 15])]], JSON_THROW_ON_ERROR)], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
            'QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'array',
        ], timeout: 15);
    // Independent writer sessions must see these fixtures, like the existing process-recovery tests.
    DB::commit();
    $waiting = false;
    try {
        Illuminate\Support\Facades\Event::listen($event, function () use ($worker, &$waiting): void {
            if ($worker->isStarted()) {
                return;
            }
            $worker->start();
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'cron_sync_progress_test')->where('wait_event_type', 'Lock')->exists();
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $worker->isRunning() && hrtime(true) < $deadline);
            expect($waiting)->toBeTrue($worker->getErrorOutput());
        });
        app(RequestCheckRun::class)($check, RunOrigin::System);
        $started = hrtime(true);
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 30);
        $seconds = (hrtime(true) - $started) / 1_000_000_000;
        expect($seconds)->toBeLessThan(10);
        fwrite(STDERR, sprintf("Serialized cron evaluation: %.3f seconds\n", $seconds));
        expect($waiting)->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        expect($worker->wait())->toBe(0);
        expect($worker->getOutput())->toContain('synced');
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed);
        expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1);
        expect($check->incidents()->sole()->resolved_at)->toBeNull();
    } finally {
        $worker->stop();
        Illuminate\Support\Facades\Event::forget($event);
        $clientId = $this->site->client_id;
        $this->site->delete();
        DB::table('clients')->where('id', $clientId)->where('is_internal', false)->delete();
        $this->owner->tokens()->delete();
        $this->owner->delete();
        DB::beginTransaction();
    }
});
