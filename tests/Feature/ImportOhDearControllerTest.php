<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Http\ListPageSize;
use Heptapod\Jobs\RunOhDearImport;
use Heptapod\Models\Check;
use Heptapod\Models\ImportRun;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Support\ImportTokenStore;
use Heptapod\Testing\OhDearAccountTransport;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('stores a complete server-held plan without exposing its token', function (): void {
    $owner = User::factory()->owner()->create();
    app(OhDearAccountTransport::class)->fake(['me' => ['id' => 1, 'name' => 'Fixture', 'email' => 'fixture@example.test', 'photo_url' => 'https://example.test/avatar.jpg', 'teams' => []],
        'monitors' => [], 'cron' => [], 'maintenance' => [], 'recurring' => [],
        'responses' => ['/api/monitors' => ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]]]);

    $response = $this->actingAs($owner)->postJson('/settings/import/ohdear/plan', ['ohdear_token' => 'fixture-plan-secret'])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('rows', [])->assertJsonPath('remote_error', null);
    $run = ImportRun::query()->findOrFail($response->json('plan_id'));
    expect($run->user_id)->toBe($owner->id)->and($run->status)->toBe(ImportRunStatus::Planned);
    expect(ImportTokenStore::get($run->id))->toBe('fixture-plan-secret');
    expect($response->getContent())->not->toContain('fixture-plan-secret');
    expect(json_encode($run->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('fixture-plan-secret');
});

it('queues a planned import once and keeps the result URL and assignments', function (): void {
    $owner = User::factory()->owner()->create();
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => 'planned', 'expected' => 1,
        'teams' => [], 'plan' => [['source_id' => '101', 'operation' => 'create', 'team_id' => 1]], 'results' => [], 'created_client_ids' => []]);
    ImportTokenStore::put($run->id, 'fixture-start-secret');
    Queue::fake([RunOhDearImport::class]);
    $payload = ['expected' => 1, 'groups' => ['team:1' => ['new_client' => '  Imported client  ']], 'overrides' => []];

    $this->actingAs($owner)->postJson('/settings/import/ohdear/runs/'.$run->id.'/start', $payload)
        ->assertCreated()->assertExactJson(['url' => route('settings.import.ohdear.runs.show', $run->id)]);
    $this->postJson('/settings/import/ohdear/runs/'.$run->id.'/start', $payload)->assertConflict();

    $this->assertDatabaseHas('import_runs', ['id' => $run->id, 'status' => 'queued', 'attempts' => 1, 'started_at' => '2026-10-31 12:00:00+00']);
    expect($run->refresh()->assignments)->toBe(['101' => ['client_id' => null, 'new_client' => 'Imported client']]);
    Queue::assertPushed(RunOhDearImport::class, fn (RunOhDearImport $job): bool => $job->runId === $run->id);
    Queue::assertPushed(RunOhDearImport::class, 1);
});

it('queues an incomplete or failed import once and increments its attempt', function (string $status): void {
    $owner = User::factory()->owner()->create();
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => $status, 'expected' => 0,
        'attempts' => 2, 'teams' => [], 'plan' => [], 'results' => [], 'created_client_ids' => []]);
    ImportTokenStore::put($run->id, 'fixture-retry-secret');
    Queue::fake([RunOhDearImport::class]);

    $this->actingAs($owner)->postJson('/settings/import/ohdear/runs/'.$run->id.'/retry')
        ->assertOk()->assertExactJson(['url' => route('settings.import.ohdear.runs.show', $run->id)]);
    $this->postJson('/settings/import/ohdear/runs/'.$run->id.'/retry')->assertConflict();

    $this->assertDatabaseHas('import_runs', ['id' => $run->id, 'status' => 'queued', 'attempts' => 3]);
    Queue::assertPushed(RunOhDearImport::class, fn (RunOhDearImport $job): bool => $job->runId === $run->id);
    Queue::assertPushed(RunOhDearImport::class, 1);
})->with(['incomplete', 'failed']);

it('rejects another operators run without writes or dispatch', function (string $operation): void {
    $owner = User::factory()->owner()->create();
    $other = User::factory()->admin()->create();
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => 'planned', 'expected' => 0,
        'teams' => [], 'plan' => [], 'results' => [], 'created_client_ids' => []]);
    Queue::fake();

    $this->actingAs($other)->postJson('/settings/import/ohdear/runs/'.$run->id.'/'.$operation)->assertForbidden();

    $this->assertDatabaseHas('import_runs', ['id' => $run->id, 'status' => 'planned', 'attempts' => 0]);
    Queue::assertNothingPushed();
})->with(['start', 'retry']);

it('rejects an expired import token without writes or dispatch', function (string $operation, string $status): void {
    $owner = User::factory()->owner()->create();
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => $status, 'expected' => 0,
        'teams' => [], 'plan' => [], 'results' => [], 'created_client_ids' => []]);
    Queue::fake();

    $this->actingAs($owner)->postJson('/settings/import/ohdear/runs/'.$run->id.'/'.$operation, ['expected' => 0, 'groups' => [], 'overrides' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('token');

    $this->assertDatabaseHas('import_runs', ['id' => $run->id, 'status' => $status, 'attempts' => 0]);
    Queue::assertNothingPushed();
})->with(['start' => ['start', 'planned'], 'retry' => ['retry', 'incomplete']]);

it('renders imported cron tasks in stable order with constant lookup queries', function (int $count): void {
    $owner = User::factory()->owner()->create();
    $store = app(CronTaskStore::class);
    $results = [];
    $expected = [];
    for ($i = 0; $i < $count; $i++) {
        $site = Site::factory()->create(['friendly_name' => $i === 0 ? null : 'Monitor '.$i]);
        $check = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
        $first = $store->create($check->id, ['name' => 'Backup '.$i, 'type' => 'simple', 'frequency_in_minutes' => 60]);
        $second = $store->create($check->id, ['name' => 'Cleanup '.$i, 'type' => 'simple', 'frequency_in_minutes' => 120]);
        $results[(string) $i] = ['source_id' => (string) $i, 'site_id' => $site->id, 'attempt' => 1];
        $expected[] = ['site' => $i === 0 ? $site->url : 'Monitor '.$i, 'site_id' => $site->id, 'tasks' => [
            ['name' => 'Backup '.$i, 'ping_url' => url('/cron/'.$first->uuid)],
            ['name' => 'Cleanup '.$i, 'ping_url' => url('/cron/'.$second->uuid)],
        ]];
    }
    $empty = Check::factory()->create(['type' => CheckKey::Cron]);
    $results['empty'] = ['source_id' => 'empty', 'site_id' => $empty->site_id, 'attempt' => 1];
    $unrelated = Check::factory()->create(['type' => CheckKey::Cron]);
    $store->create($unrelated->id, ['name' => 'Not imported', 'type' => 'simple', 'frequency_in_minutes' => 60]);
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => 'completed', 'expected' => $count + 1,
        'attempts' => 1, 'teams' => [], 'plan' => [], 'results' => array_reverse($results, preserve_keys: true), 'created_client_ids' => []]);
    $version = $this->actingAs($owner)->get('/settings/profile')->assertOk()->inertiaPage()['version'];
    DB::enableQueryLog();
    DB::flushQueryLog();

    // Only the import run, so the shared sidebar props (their own checks reads) stay out of the count.
    $response = $this->get('/settings/import/ohdear/runs/'.$run->id, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'settings/import-ohdear-run', 'X-Inertia-Partial-Data' => 'importRun']);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk()->assertJsonPath('component', 'settings/import-ohdear-run')->assertJsonPath('props.importRun.cron', $expected);
    if ($count === 12) {
        $this->get('/settings/import/ohdear/runs/'.$run->id.'?import_run_cron_per_page=10&import_run_cron_page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->has('importRun.cron', 2)
                ->where('importRun.cron_pagination.total', 12)
                ->where('importRun.cron_pagination.last_page', 2)
                ->where('importRun.cron.0.site_id', $expected[10]['site_id']));
    }
    foreach (['sites', 'checks', 'cron_checks'] as $table) {
        $lookups = array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select * from "'.$table.'"'));
        expect(count($lookups))->toBe(1, $table.' must be fetched once for '.$count.' imported checks');
    }
})->with(['one cron check' => 1, 'twelve cron checks' => 12]);

it('renders an empty cron list without task queries when imported sites have no cron checks', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => 'completed', 'expected' => 1,
        'attempts' => 1, 'teams' => [], 'plan' => [], 'results' => ['101' => ['source_id' => '101', 'site_id' => $site->id, 'attempt' => 1]], 'created_client_ids' => []]);
    $version = $this->actingAs($owner)->get('/settings/profile')->assertOk()->inertiaPage()['version'];
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get('/settings/import/ohdear/runs/'.$run->id, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk()->assertJsonPath('props.importRun.cron', []);
    $taskQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '"cron_checks"'));
    expect($taskQueries)->toBe([]);
});

it('pages import run rows in plan order, keeps whole-run summaries and respects the remembered size', function (): void {
    $owner = User::factory()->owner()->create();
    $member = User::factory()->create();
    $plan = [];
    $results = [];
    foreach (range(1, 12) as $i) {
        $id = 'src-'.$i;
        $plan[] = ['source_id' => $id];
        $results[$id] = [
            'source_id' => $id,
            'url' => "https://site-{$i}.example.test",
            'label' => "Site {$i}",
            'operation' => 'create',
            'notices' => ['import.notice.shared'],
            'attempt' => 1,
            'inherits_alert_timing' => $i <= 8,
        ];
    }
    $run = ImportRun::query()->create([
        'user_id' => $owner->id,
        'source' => 'ohdear',
        'status' => 'completed',
        'expected' => 12,
        'attempts' => 1,
        'teams' => [],
        'plan' => $plan,
        'results' => $results,
        'created_client_ids' => [],
    ]);

    $this->actingAs($owner)->get('/settings/import/ohdear/runs/'.$run->id.'?per_page=10')
        ->assertOk()
        ->assertCookie(ListPageSize::Cookie, json_encode(['import_run_rows' => 10]), false)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('importRun.rows', 10)
            ->where('importRun.rows.0.source_id', 'src-1')
            ->where('importRun.rows_pagination.total', 12)
            ->where('importRun.rows_pagination.last_page', 2)
            ->where('importRun.common_notices', ['import.notice.shared'])
            ->where('importRun.alert_timing', ['inherited' => 8, 'overridden' => 4]));

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['import_run_rows' => 10, 'import_plan_rows' => 20]));
    $this->get('/settings/import/ohdear/runs/'.$run->id.'?page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('importRun.rows', 2)
            ->where('importRun.rows.0.source_id', 'src-11')
            ->where('importRun.rows.1.source_id', 'src-12')
            ->where('importRun.processed', 12)
            ->where('importRun.common_notices', ['import.notice.shared'])
            ->where('importRun.alert_timing', ['inherited' => 8, 'overridden' => 4]));

    // Plan page reads its remembered page size from the same cookie.
    $this->get('/settings/import/ohdear')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plan_page_size', 20));

    $this->get('/settings/import/ohdear/runs/'.$run->id.'?per_page=7')->assertSessionHasErrors('per_page');

    // Permissions unchanged: Members cannot view import runs or the plan page.
    $this->actingAs($member)->get('/settings/import/ohdear/runs/'.$run->id.'?page=2')->assertForbidden();
    $this->actingAs($member)->get('/settings/import/ohdear')->assertForbidden();
});

it('fails a queued import immediately when queue dispatch fails on start or retry so it can be retried', function (string $operation, string $initialStatus): void {
    $owner = User::factory()->owner()->create();
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => $initialStatus, 'expected' => 0,
        'attempts' => $initialStatus === 'planned' ? 0 : 1, 'teams' => [], 'plan' => [], 'results' => [], 'created_client_ids' => []]);
    ImportTokenStore::put($run->id, 'fixture-dispatch-secret');
    Queue::addConnector('failing-redis', static fn (): ConnectorInterface => new class implements ConnectorInterface
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
    config(['queue.connections.redis.driver' => 'failing-redis']);

    $payload = $operation === 'start' ? ['expected' => 0, 'groups' => [], 'overrides' => []] : [];
    $this->actingAs($owner)->postJson('/settings/import/ohdear/runs/'.$run->id.'/'.$operation, $payload)->assertServerError();

    expect($run->refresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($run->error)->toBe('unexpected')
        ->and($run->finished_at)->not->toBeNull();
    expect(ImportTokenStore::get($run->id))->toBe('fixture-dispatch-secret');

    // Once the queue recovers, the failed import can be retried immediately.
    Queue::fake([RunOhDearImport::class]);
    $this->actingAs($owner)->postJson('/settings/import/ohdear/runs/'.$run->id.'/retry')->assertOk();
    expect($run->refresh()->status)->toBe(ImportRunStatus::Queued);
})->with([
    'start' => ['start', 'planned'],
    'retry' => ['retry', 'incomplete'],
]);

it('marks a queued import failed when the worker job fails before claiming the run', function (): void {
    $owner = User::factory()->owner()->create();
    $run = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => 'queued', 'expected' => 0,
        'attempts' => 1, 'teams' => [], 'plan' => [], 'results' => [], 'created_client_ids' => []]);

    new RunOhDearImport($run->id)->failed(new RuntimeException('Worker failed before handle'));

    expect($run->refresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($run->error)->toBe('unexpected')
        ->and($run->finished_at)->not->toBeNull();
});

it('reconciles stale queued and running imports through the scheduled reconciler without disturbing fresh ones', function (): void {
    $owner = User::factory()->owner()->create();
    $staleQueued = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => 'queued', 'expected' => 0,
        'attempts' => 1, 'teams' => [], 'plan' => [], 'results' => [], 'created_client_ids' => []]);
    $freshQueued = ImportRun::query()->create(['user_id' => $owner->id, 'source' => 'ohdear', 'status' => 'queued', 'expected' => 0,
        'attempts' => 1, 'teams' => [], 'plan' => [], 'results' => [], 'created_client_ids' => []]);
    DB::table('import_runs')->where('id', $staleQueued->id)->update(['updated_at' => now()->subSeconds(ExecutionBudget::RetryAfter + 1)]);

    $event = collect(app(Schedule::class)->events())
        ->first(static fn (Event $scheduled): bool => $scheduled->description === 'reconcile-queued-work');
    expect($event)->not->toBeNull();
    $event->run(app());

    expect($staleQueued->refresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($staleQueued->error)->toBe('unexpected')
        ->and($staleQueued->finished_at)->not->toBeNull()
        ->and($freshQueued->refresh()->status)->toBe(ImportRunStatus::Queued);
});
