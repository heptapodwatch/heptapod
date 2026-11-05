<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Testing\FakeTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Tags\Tag;
use Tests\Support\QueryPlanWork;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('provides installed schemas and only visible clients without secrets', function (): void {
    $client = Client::factory()->create();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($client);
    $secret = 'add-site-real-secret';
    $site = Site::factory()->for($client)->create(['crawler_headers' => [['name' => 'Authorization', 'value' => $secret]]]);
    $site->attachTag('Visible tag', 'sites');
    $hidden = Site::factory()->create();
    $hidden->attachTag('Invisible tag', 'sites');
    Tag::findOrCreate('Unused tag', 'sites', 'en');
    $keys = array_map(static fn (CheckKey $key): string => $key->value, app(CheckRegistry::class)->keys());

    $response = $this->actingAs($user)->get('/sites/create')->assertInertia(fn (Assert $page): Assert => $page->component('sites/create')->has('clients', 1)
        ->where('clients.0.id', $client->id)->missing('tags')->has('checks', count($keys)));
    $this->get('/sites/create?client_id='.$client->id)->assertInertia(fn (Assert $page): Assert => $page->where('selected_client_id', $client->id));
    $this->get('/sites/create?client_id='.$hidden->client_id)->assertNotFound();
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->has('tags', 1)->where('tags.0.name', 'Visible tag'));
    $checks = array_column($response->inertiaProps()['checks'], null, 'key');
    expect(array_keys($checks))->toEqual($keys)
        ->and($checks['application_health']['config_schema']['fields'])->toHaveKeys(['result_url', 'secret'])
        ->and($checks['cron']['task_definition_schema']['fields'])->toHaveKeys(['name', 'type', 'frequency_in_minutes', 'cron_expression', 'server_timezone']);
    $props = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
    foreach ([$secret, 'Invisible tag', 'Unused tag'] as $needle) {
        expect($props)->not->toContain($needle);
    }
});

it('creates a site through the shared action and returns inline configuration errors', function (): void {
    $client = Client::factory()->create();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($client);
    $this->actingAs($user)->post('/sites', ['client_id' => $client->id, 'url' => 'https://member.example'])->assertForbidden();
    $this->assertDatabaseMissing('sites', ['url' => 'https://member.example']);
    $user->syncRoles('Admin');
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('example.test', ['127.0.0.1'])->respondWith('www.example.test', ['127.0.0.1']));
    $this->post('/sites', ['client_id' => $client->id, 'url' => 'https://example.test', 'friendly_name' => 'New site', 'tags' => ['production']])->assertRedirect();
    $site = Site::query()->where('url', 'https://example.test')->firstOrFail();
    expect($site->tags->first()?->getTranslation('name', 'en'))->toBe('production');
    $expected = ['certificate_health', 'dns', 'domain', 'performance', 'uptime'];
    expect($site->checks->where('enabled', true)->pluck('type')->map(static fn (CheckKey $key): string => $key->value)->all())->toEqualCanonicalizing($expected);
    $this->post('/sites', ['client_id' => $client->id, 'url' => 'https://bad.example', 'checks' => ['application_health']])->assertSessionHasErrors();
    $this->assertDatabaseMissing('sites', ['url' => 'https://bad.example']);
    $this->postJson('/sites', ['client_id' => $client->id, 'url' => 'https://cron.example', 'checks' => ['cron'], 'cron_task_definitions' => [['name' => 'Backup']]])->assertUnprocessable()->assertJsonValidationErrors('cron_task_definitions');
    $this->post('/sites', ['client_id' => Client::factory()->create()->id, 'url' => 'https://www.example.test'])->assertRedirect();
    $this->assertDatabaseHas('sites', ['url' => 'https://www.example.test']);
});

it('ignores stale presentation selection for creation lists and authorized deep links', function (): void {
    $this->withoutVite();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Owner');
    $selected = Client::factory()->create(['name' => 'Selected client']);
    $other = Client::factory()->create();
    $user->clients()->attach([$selected->id, $other->id]);
    $check = Check::factory()->for(Site::factory()->for($other))->create();
    $incident = Incident::factory()->for($check)->create();

    $this->actingAs($user)->withSession(['selected_client_id' => $selected->id]);
    $response = $this->get('/sites/create')->assertOk();
    expect(collect($response->inertiaProps()['clients'])->pluck('id')->all())->toContain($selected->id, $other->id);
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('second.fixtures.test', ['127.0.0.1']));
    $this->post('/sites', ['client_id' => $other->id, 'url' => 'https://second.fixtures.test'])->assertRedirect();
    $this->assertDatabaseHas('sites', ['client_id' => $other->id, 'url' => 'https://second.fixtures.test']);
    $response->assertSessionMissing('selected_client_id');
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('pagination.total', 2)->missing('sites'));
    $this->get('/sites', ['X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sites'])->assertInertia(fn (Assert $page): Assert => $page->has('sites', 2));
    $this->get('/sites/'.$check->site_id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->missing('outside_scope')->missing('scope_name')->where('site.id', $check->site_id));
    $this->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->missing('outside_scope')->where('check.id', $check->id));
    $this->get('/incidents/'.$incident->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->missing('outside_scope')->missing('scope_name')->where('incident.id', $incident->id)->where('client.id', $other->id));
    $this->get('/clients/'.$other->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->missing('outside_scope')->where('summary.sites', 2));
});

it('projects actual Tasks setup permissions for wizard check page and token page without granting Member writes', function (string $role, bool $canWrite): void {
    $this->withoutVite();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole(explode('+', $role));
    $client = Client::factory()->create();
    $user->clients()->attach($client);
    $check = Check::factory()->for(Site::factory()->for($client))->create(['type' => CheckKey::Cron]);

    $this->actingAs($user)->get('/sites/create?client_id='.$client->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('can_create_site', $canWrite)->where('selected_client_id', $client->id));
    $this->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page->where('can_run', $canWrite));
    $tokens = $this->get('/settings/api-tokens')->assertOk()->inertiaProps()['tokensPage'];
    expect(in_array('checks:write', $tokens['presets']['write'], true))->toBe($canWrite);
    if (! $canWrite) {
        $this->postJson('/settings/api-tokens', ['name' => 'Denied setup', 'abilities' => ['checks:write']])->assertUnprocessable();
        expect($user->tokens()->count())->toBe(0);
        $this->post('/sites', ['client_id' => $client->id, 'url' => 'https://denied-task-setup.example', 'checks' => []])->assertForbidden();
        $this->assertDatabaseMissing('sites', ['url' => 'https://denied-task-setup.example']);
    }
})->with(['operator Owner' => ['Owner', true], 'operator Admin' => ['Admin', true], 'multi-role operator' => ['Owner+Member', true], 'readonly Member' => ['Member', false]]);

it('keeps the readonly wizard policy projection equal to Gate for supported roles and visible foreign archived and disabled contexts', function (string $role): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole(explode('+', $role));
    $visible = Client::factory()->create();
    $foreign = Client::factory()->create();
    $archived = Client::factory()->create(['archived_at' => now()]);
    $user->clients()->attach([$visible->id, $archived->id]);
    $policy = app(SitePolicy::class);
    $gate = Gate::forUser($user);
    foreach ([$visible->id => $role !== 'Member', $foreign->id => $role !== 'Member', $archived->id => false] as $clientId => $expected) {
        expect($policy->create($user, $clientId)->allowed())->toBe($expected)
            ->and($gate->allows('create', [Site::class, $clientId]))->toBe($expected);
    }
    $user->forceFill(['disabled_at' => now()])->save();
    expect($policy->create($user, $visible->id)->allowed())->toBeFalse()
        ->and($gate->allows('create', [Site::class, $visible->id]))->toBeFalse();
})->with(['Owner', 'Admin', 'Member', 'Owner+Member']);

it('attributes validation errors to the check that rejected a shared setting', function (): void {
    $client = Client::factory()->create();
    $user = User::factory()->create();
    $user->assignRole('Owner');

    $this->actingAs($user)->post('/sites', [
        'client_id' => $client->id,
        'url' => 'https://shared-expiry.example',
        'checks' => ['certificate_health', 'domain'],
        'certificate_health_check_settings' => ['expires_soon_threshold_in_days' => -1],
        'domain_check_settings' => ['expires_soon_threshold_in_days' => 30],
    ])->assertSessionHasErrors('checks.certificate_health.expires_soon_threshold_in_days')
        ->assertSessionDoesntHaveErrors('checks.domain.expires_soon_threshold_in_days');
    $this->assertDatabaseMissing('sites', ['url' => 'https://shared-expiry.example']);
});

it('uses the same cron task schema for props and task validation', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $definitions = app(CronDefinitions::class);
    $task = $definitions->create($check->id, ['name' => 'Backup', 'type' => 'simple', 'frequency_in_minutes' => 5]);
    expect($task->frequency_in_minutes)->toBe(5);
    expect(fn () => $definitions->create($check->id, ['name' => 'Backup', 'type' => 'simple', 'frequency_in_minutes' => '5']))->toThrow(ValidationException::class);
    expect(fn () => $definitions->create($check->id, ['name' => 'Backup', 'type' => 'cron', 'cron_expression' => 'invalid', 'server_timezone' => 'UTC']))->toThrow(ValidationException::class);
    expect(fn () => $definitions->create($check->id, ['name' => 'Backup', 'type' => 'cron', 'cron_expression' => '* * * * *']))->toThrow(ValidationException::class);
    expect(fn () => $definitions->create($check->id, ['name' => 'Backup', 'type' => 'simple', 'frequency_in_minutes' => 5, 'unknown' => true]))->toThrow(ValidationException::class);
});

it('loads add-site client options without N+1 queries or removed tag options even without planner statistics', function (int $count, int $sitesPerClient, int $tagCount): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $clients = Client::factory()->count($count)->create();
    $user->clients()->attach($clients);
    foreach ($clients as $index => $client) {
        foreach (Site::factory()->for($client)->count($sitesPerClient)->create() as $siteIndex => $site) {
            $site->attachTag('Tag '.(($index * $sitesPerClient + $siteIndex) % $tagCount), 'sites');
        }
    }
    DB::statement("SET LOCAL statement_timeout = '2s'");
    expect(DB::scalar('SHOW statement_timeout'))->toBe('2s');
    $this->withoutExceptionHandling();
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get('/sites/create');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page->has('clients', $count)->missing('tags'));
    expect(collect($queries)->filter(static fn (array $query): bool => str_contains($query['query'], 'from "tags"') || str_contains($query['query'], 'from "taggables"')))->toBeEmpty();
    // Client avatars add two batched relation reads to the cold page load.
    expect(count($queries))->toBeLessThanOrEqual(14);
    if ($count === 300) {
        $clientQuery = collect($queries)->first(fn (array $query): bool => str_starts_with($query['query'], 'select "clients".* from "clients"'));
        expect($clientQuery)->not->toBeNull();
        expect(QueryPlanWork::forQuery($clientQuery['query'], $clientQuery['bindings']))->toBeLessThan(12 * $count);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/sites', ['X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'tags'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('tags', $tagCount));
    $tagQueries = DB::getQueryLog();
    DB::disableQueryLog();
    if ($count === 300) {
        $visibilityQuery = collect($tagQueries)->first(fn (array $query): bool => str_starts_with($query['query'], 'select "sites"."id" from "sites"'));
        expect($visibilityQuery)->not->toBeNull();
        expect(QueryPlanWork::forQuery($visibilityQuery['query'], $visibilityQuery['bindings']))->toBeLessThan(12 * $count * $sitesPerClient);
        $tagQuery = collect($tagQueries)->first(fn (array $query): bool => str_contains($query['query'], 'from "tags"'));
        $associationQuery = collect($tagQueries)->first(fn (array $query): bool => str_contains($query['query'], 'from "taggables"'));
        expect($tagQuery)->not->toBeNull()->and($associationQuery)->not->toBeNull()
            ->and(count($tagQuery['bindings']))->toBeLessThan(3)
            ->and(count($associationQuery['bindings']))->toBeLessThan(3);
        expect(QueryPlanWork::forQuery($tagQuery['query'], $tagQuery['bindings']))->toBeLessThan(12 * $count * $sitesPerClient);
    }
})->with([[50, 1, 50], [300, 1, 300], [3, 100, 50]]);

it('does not disclose client existence on site creation and denies roleless callers consistently', function (): void {
    config(['app.debug' => false]);
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $hidden = Client::factory()->create();
    $missing = $hidden->id + 100000;
    $this->actingAs($user);

    $hiddenResponse = $this->postJson('/sites', ['client_id' => $hidden->id, 'url' => 'https://example.test'])->assertNotFound();
    $missingResponse = $this->postJson('/sites', ['client_id' => $missing, 'url' => 'https://example.test'])->assertNotFound();
    expect($missingResponse->getContent())->toBe($hiddenResponse->getContent());
    $this->actingAs(User::factory()->create());
    $this->postJson('/sites', ['client_id' => $hidden->id, 'url' => 'https://example.test'])->assertForbidden();
    $this->postJson('/sites', ['client_id' => $missing, 'url' => 'https://example.test'])->assertForbidden();
});
