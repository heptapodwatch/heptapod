<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Enums\ClientScope;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Tags\Tag;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('requires authentication and an instance role for the sites list', function (): void {
    $this->get('/sites')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/sites')->assertForbidden();
});

it('orders sites by severity then locale-aware name and permits explicit sorting and filters', function (): void {
    $user = User::factory()->create(['locale' => 'de']);
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $rows = [
        ['Zulu', CheckResult::Succeeded, false],
        ['Alpha', CheckResult::Pending, false],
        ['Warning', CheckResult::Warning, false],
        ['Error', CheckResult::Errored, false],
        ['Down', CheckResult::Failed, true],
        ['Äpfel', CheckResult::Succeeded, false],
    ];
    foreach ($rows as [$name, $result, $incident]) {
        $site = Site::factory()->for($client)->create(['friendly_name' => $name]);
        $check = Check::factory()->for($site)->create(['latest_run_result' => $result]);
        if ($incident) {
            Incident::factory()->for($check)->create();
        }
    }

    $this->actingAs($user)->get('/sites')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('sites/index')->missing('sites')
        ->where('pagination.per_page', 50)->where('pagination.total', 6)->where('filters.sort', 'status')
        ->where('summary.total', 6)->where('summary.attention', 3)->where('summary.paused', 0)
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
            ->has('sites', 6)->missing('summary')->missing('pagination')
            ->where('sites.0.name', 'Down')->where('sites.0.checks.0.open_incident_id', Incident::query()->sole()->id)
            ->where('sites.1.state', 'check-error')->where('sites.2.state', 'warning')
            ->where('sites.3.state', 'pending')->where('sites.4.name', 'Äpfel')->where('sites.5.name', 'Zulu')));
    $this->get('/sites?sort=name&filter[state]=ok')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
            ->has('sites', 2)->where('sites.0.name', 'Äpfel')->where('sites.1.name', 'Zulu')));
    $this->get('/sites?filter[search]=Down&filter[client_id]='.$client->id)->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->has('sites', 1)->where('sites.0.name', 'Down')));
    $this->get('/sites?filter[attention]=1')->assertInertia(fn (Assert $page): Assert => $page
        ->where('summary.total', 6)->where('summary.attention', 3)->where('summary.paused', 0)->where('filters.attention', true)
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->has('sites', 3)));
    $down = Site::query()->where('friendly_name', 'Down')->sole();
    $down->attachTag(Tag::findOrCreate('production', 'sites', 'en'));
    $this->get('/sites?filter[tag]=production')->assertInertia(fn (Assert $page): Assert => $page
        ->where('tags.0.name', 'production')
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->has('sites', 1)->where('sites.0.name', 'Down')));
    Check::factory()->for($down)->create(['type' => CheckKey::Performance]);
    $this->get('/sites?filter[check]=performance')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.check', 'performance')
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->has('sites', 1)->where('sites.0.name', 'Down')));
    $this->get('/sites?filter[check]=cron')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->has('sites', 0)));
    $this->get('/sites?filter[check]=unknown')->assertSessionHasErrors('filter.check');
    $this->get('/sites?sort=unexpected')->assertStatus(400);
    $this->get('/sites?filter[state]=not-a-state')->assertSessionHasErrors('filter.state');
    $this->get('/sites?filter[attention]=not-a-boolean')->assertSessionHasErrors('filter.attention');
});

it('scopes sites and client filter options and never exposes stored secrets', function (): void {
    $allowed = Client::factory()->create();
    $hidden = Client::factory()->create();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($allowed);
    $secret = 'site-list-private-token';
    $site = Site::factory()->for($allowed)->create(['crawler_headers' => [['name' => 'Authorization', 'value' => $secret]]]);
    $check = Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Failed]);
    Incident::factory()->for($check)->create();
    $check->forceFill(['config_secrets' => ['token' => $secret]])->save();
    $site->attachTag(Tag::findOrCreate('public-tag', 'sites', 'en'));
    Site::factory()->for($hidden)->create()->attachTag(Tag::findOrCreate('secret-tag', 'sites', 'en'));

    $response = $this->actingAs($user)->get('/sites')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->missing('sites')->has('clients', 1)->where('clients.0.id', $allowed->id)
        ->where('pagination.total', 1)->where('pagination.per_page', 50)
        ->where('summary.total', 1)->where('summary.attention', 1)
        ->where('tags.0.name', 'public-tag')->where('can_create', false)
        ->has('tiles')->has('needs_attention', 1)->where('needs_attention.0.id', $site->id)
        ->where('needs_attention_total', 1));
    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    expect($site->refresh()->crawler_headers[0]['value'])->toBe($secret);
    $this->get('/sites?filter[client_id]='.$hidden->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('pagination.total', 0)
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->has('sites', 0)));
    $this->get('/sites', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $response->inertiaPage()['version'],
        'X-Inertia-Partial-Component' => 'sites/index',
        'X-Inertia-Partial-Data' => 'sites',
    ])->assertOk()->assertJsonPath('component', 'sites/index')->assertJsonCount(1, 'props.sites')
        ->assertJsonPath('props.sites.0.id', $site->id)->assertJsonMissingPath('props.clients')->assertJsonMissingPath('props.pagination')
        ->assertDontSee($secret);
});

it('omits paused and currently maintained Lighthouse sites from attention but includes planned maintenance', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $visible = [];
    foreach (['active', 'paused', 'maintenance', 'planned'] as $state) {
        $site = Site::factory()->create(match ($state) {
            'paused' => ['paused_at' => now()->subMinute()],
            default => [],
        });
        Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse, 'summary' => 'checks.engine.errored',
            'latest_run_result' => CheckResult::Errored]);
        if (in_array($state, ['maintenance', 'planned'], true)) {
            MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => $state === 'planned' ? now()->addHour() : now()->subHour(),
                'ends_at' => now()->addHours(2)]);
        }
        if (in_array($state, ['active', 'planned'], true)) {
            $visible[] = $site->id;
        }
    }

    $props = $this->actingAs($owner)->get('/sites')->assertOk()->inertiaProps();

    expect($props['needs_attention_total'])->toBe(2)
        ->and(array_column($props['needs_attention'], 'id'))->toEqualCanonicalizing($visible);
});

it('counts a site marked for deletion as paused instead of needing attention', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['marked_for_deletion_at' => now()->subMinute()]);
    Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse, 'summary' => 'checks.engine.errored',
        'latest_run_result' => CheckResult::Errored]);

    $props = $this->actingAs($owner)->get('/sites')->assertOk()->inertiaProps();

    expect($props['needs_attention_total'])->toBe(0)
        ->and($props['needs_attention'])->toBe([])
        ->and($props['summary']['attention'])->toBe(0)
        ->and($props['summary']['paused'])->toBe(1);
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
            ->has('sites', 1)->where('sites.0.id', $site->id)->where('sites.0.state', 'paused')));
});

it('presents scoped Lighthouse summaries and masks cause text on first load and deferred site rows', function (): void {
    $allowed = Client::factory()->create();
    $hidden = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($allowed);
    $expected = [];
    foreach ([['lighthouse.warning', ['category' => 'seo'], CheckResult::Warning, true, $allowed],
        ['lighthouse.warning', ['category' => 'seo'], CheckResult::Warning, true, $allowed],
        ['checks.engine.errored', [], CheckResult::Errored, true, $allowed],
        ['lighthouse.warning', ['category' => 'seo'], CheckResult::Warning, true, $hidden],
        ['lighthouse.warning', ['category' => 'seo'], CheckResult::Warning, false, $allowed],
        ['lighthouse.succeeded', [], CheckResult::Succeeded, true, $allowed],
    ] as [$key, $params, $result, $enabled, $client]) {
        $site = Site::factory()->for($client)->create();
        $check = Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse, 'summary' => $key, 'summary_params' => $params,
            'latest_run_result' => $result, 'enabled' => $enabled]);
        if ($enabled && $client->is($allowed) && $result !== CheckResult::Succeeded) {
            $expected[$check->id] = ['key' => $key, 'params' => $params];
        }
    }
    $secretSite = Site::factory()->for($allowed)->create(['crawler_headers' => [['name' => 'Authorization', 'value' => 'private-lighthouse-token']]]);
    $secretCheck = Check::factory()->for($secretSite)->create(['type' => CheckKey::Lighthouse, 'summary' => 'lighthouse.warning',
        'summary_params' => ['detail' => 'private-lighthouse-token'], 'latest_run_result' => CheckResult::Warning]);
    $expected[$secretCheck->id] = ['key' => 'lighthouse.warning', 'params' => ['detail' => '[redacted]']];

    $response = $this->actingAs($member)->get('/sites')->assertOk();
    $props = $response->inertiaProps();

    expect($props['needs_attention_total'])->toBe(4);
    $summaries = [];
    foreach ($props['needs_attention'] as $site) {
        foreach ($site['checks'] as $check) {
            $summaries[$check['id']] = ['key' => $check['summary'], 'params' => $check['summary_params']];
        }
    }
    expect($summaries)->toEqual($expected)
        ->and(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('private-lighthouse-token');
    $response->assertInertia(fn (Assert $page): Assert => $page->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
        ->has('sites', 6)->where('sites', function (Collection $sites) use ($secretCheck): bool {
            $checks = $sites->flatMap(fn (array $site): array => $site['checks'])->keyBy('id');
            expect($checks[$secretCheck->id]['summary'])->toBe('lighthouse.warning')
                ->and($checks[$secretCheck->id]['summary_params'])->toBe(['detail' => '[redacted]'])
                ->and(json_encode($sites, JSON_THROW_ON_ERROR))->not->toContain('private-lighthouse-token');

            return true;
        })));
});

it('does not display a component snooze as a whole-check snooze', function (): void {
    $this->travelTo(now()->startOfMinute());
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    CheckSnooze::factory()->for($check)->create(['component_type' => SuppressionComponent::Cron, 'component_id' => 1]);

    $this->actingAs($user)->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->where('sites.0.checks.0.snoozed_until', null)));
    $wholeCheck = CheckSnooze::factory()->for($check)->create();
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->where('sites.0.checks.0.snoozed_until', $wholeCheck->ends_at->toISOString())));
});

it('batches the check strip and recent sparkline within a fixed query budget', function (int $count): void {
    $this->travelTo(now()->startOfMinute());
    $client = Client::factory()->create();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $sites = Site::factory()->count($count)->for($client)->create();
    foreach ($sites as $index => $site) {
        $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Succeeded]);
        CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute(), 'result' => RunResult::Succeeded, 'metrics' => ['total_time_in_seconds' => ($index + 1) / 1000]]);
    }
    $this->actingAs($user)->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->missing('sites')->where('pagination.total', $count));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get('/sites', [
        'X-Inertia-Partial-Component' => 'sites/index',
        'X-Inertia-Partial-Data' => 'sites',
    ]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('sites/index')
        ->has('sites', 50)->missing('pagination')->has('sites.0.checks', 1)->has('sites.0.response', 1)
        ->where('sites.0.uptime', null));
    expect(count($queries))->toBeLessThanOrEqual(24);
    $sampleQueries = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'FROM list_response_buckets'));
    expect($sampleQueries)->toHaveCount(1);
    $props = $response->inertiaProps();
    foreach ($props['sites'] as $row) {
        $index = $sites->search(fn (Site $site): bool => $site->id === $row['id']);
        expect($row['response'][0]['value'])->toEqual($index + 1);
    }
})->with([50, 300]);

it('uses the same display name in SQL projections, incidents and notification events', function (string $url, ?string $friendlyName, string $expected): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create(['url' => $url, 'friendly_name' => $friendlyName]);
    $check = Check::factory()->for($site)->create();
    $incident = Incident::factory()->for($check)->create();
    $event = Event::factory()->for($check)->for($incident)->create(['type' => 'incident.opened']);

    $response = $this->actingAs($user)->get('/sites/'.$site->id)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('site.name', $expected)->where('incidents.0.site_name', $expected)->where('events.0.site_name', $expected));
    expect($site->displayName())->toBe($expected);
    $message = app(NotificationMessage::class)->build($event);
    expect($message->siteLabel)->toBe($expected);
    expect($message->titleParams['site'])->toBe($expected);
    expect($message->link)->toBe(route('incidents.show', ['incident' => $incident->id]));
})->with([
    'friendly name' => ['https://example.com/shop?private=1#part', 'Friendly', 'Friendly'],
    'path' => ['https://example.com/shop/?private=1#part', null, 'example.com/shop'],
    'port' => ['http://example.com:8080/shop/', '', 'example.com:8080/shop'],
    'IDN' => ['https://bücher.example/shop/?private=1', null, 'bücher.example/shop'],
    'root' => ['https://example.com/', null, 'example.com'],
    'uppercase host' => ['https://EXAMPLE.com/shop/', null, 'EXAMPLE.com/shop'],
    'userinfo' => ['https://user:password@example.com/shop/', null, 'example.com/shop'],
    'query email' => ['https://example.com?email=alice@other.test', null, 'example.com'],
    'fragment email' => ['https://example.com#alice@other.test', null, 'example.com'],
    'IPv6' => ['https://[2001:db8::1]/shop/', null, '[2001:db8::1]/shop'],
    'IPv6 port and credentials' => ['https://user:pass@[2001:db8::1]:8443/shop/?email=alice@other.test', null, '[2001:db8::1]:8443/shop'],
]);
