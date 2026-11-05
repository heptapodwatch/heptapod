<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Jobs\RunOhDearImport;
use Heptapod\Models\Check;
use Heptapod\Models\ImportRun;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Support\ImportTokenStore;
use Heptapod\Testing\OhDearAccountTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    $response = $this->get('/settings/import/ohdear/runs/'.$run->id, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk()->assertJsonPath('component', 'settings/import-ohdear-run')->assertJsonPath('props.importRun.cron', $expected);
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
