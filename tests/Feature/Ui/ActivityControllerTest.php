<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
});

/** Activity as the Inertia client reads it (the page JSON keeps `scrollProps`); `$only` makes it a partial reload. */
function activityPage(string $url, ?string $only = null, ?string $reset = null): TestResponse
{
    $version = test()->get('/activity')->assertOk()->inertiaPage()['version'];

    return test()->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        ...($only === null ? [] : ['X-Inertia-Partial-Component' => 'activity/index', 'X-Inertia-Partial-Data' => $only]),
        ...($reset === null ? [] : ['X-Inertia-Reset' => $reset])])->assertOk();
}

it('omits initial scheduled-task observations from Activity', function (): void {
    $user = User::factory()->create(['last_seen_at' => now()->subHour()]);
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    Event::factory()->for($check)->create(['type' => 'cron.task_result_changed', 'occurred_at' => now()->subMinutes(2),
        'from_result' => CheckResult::Pending, 'to_result' => CheckResult::Succeeded,
        'payload' => ['name' => 'Routine sync', 'from_result' => null, 'result' => 'succeeded']]);
    $changed = Event::factory()->for($check)->create(['type' => 'cron.task_result_changed', 'occurred_at' => now()->subMinute(),
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed,
        'payload' => ['name' => 'Routine sync', 'from_result' => 'succeeded', 'result' => 'failed']]);

    $this->actingAs($user)->get('/activity?period=all')->assertInertia(fn (Assert $page): Assert => $page
        ->missing('total')->has('events.data', 1)->where('events.data.0.latest.id', $changed->id)->where('events.data.0.event_count', 1)->missing('head'));
});

it('finds current and deleted-site activity by the translated No client label without leaking scope', function (string $locale, string $label, bool $deleted): void {
    $internal = Client::query()->where('is_internal', true)->firstOrFail();
    $site = Site::factory()->for($internal)->create();
    $check = Check::factory()->for($site)->create();
    $event = Event::factory()->for($check)->create(['occurred_at' => now()->subMinute(), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    if ($deleted) {
        $site->delete();
    }
    $owner = User::factory()->owner()->create(['locale' => $locale]);
    $this->actingAs($owner)->get('/activity?'.http_build_query(['q' => $label]))
        ->assertInertia(fn (Assert $page): Assert => $page->has('events.data', 1)->where('events.data.0.latest.id', $event->id)->where('events.data.0.latest.client_name', $label));

    $member = User::factory()->member()->create(['locale' => $locale, 'client_scope' => ClientScope::Restricted]);
    $this->actingAs($member)->get('/activity?'.http_build_query(['q' => $label]))
        ->assertInertia(fn (Assert $page): Assert => $page->has('events.data', 0));
})->with(['en current' => ['en', 'No client', false], 'en deleted' => ['en', 'No client', true], 'de current' => ['de', 'Ohne Kunde', false], 'de deleted' => ['de', 'Ohne Kunde', true]]);

it('paginates state changes within visible clients after applying server filters', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $visible = Client::factory()->create(['name' => 'Visible']);
    $hidden = Client::factory()->create(['name' => 'Hidden']);
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($visible);
    $check = Check::factory()->for(Site::factory()->for($visible)->create(['friendly_name' => 'Needle']))->create(['type' => CheckKey::Uptime]);
    $other = Check::factory()->for(Site::factory()->for($hidden)->create(['friendly_name' => 'Hidden needle']))->create();
    $events = [];
    foreach (range(1, 23) as $index) {
        $events[] = Event::factory()->for($check)->create(['occurred_at' => now()->subMinutes($index), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    }
    Event::factory()->for($other)->create(['occurred_at' => now()->subMinute(), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);

    $this->actingAs($user);
    $first = activityPage('/activity?q=Needle&check=uptime');
    expect(array_column($first->json('props.events.data.*.latest'), 'id'))->toBe(array_column($events, 'id'))
        ->and($first->json('scrollProps.events.nextPage'))->toBeNull();
    $response = activityPage('/activity?q=Needle&check=uptime&before='.$events[19]->id, 'events');
    expect(array_column($response->json('props.events.data.*.latest'), 'id'))->toBe(array_column(array_slice($events, 20), 'id'))
        ->and($response->json('props.events.data.0.latest.client_name'))->toBe('Visible')
        ->and($response->json('scrollProps.events.nextPage'))->toBeNull()
        ->and(json_encode($first->json('props'), JSON_THROW_ON_ERROR))->not->toContain('Hidden needle')
        ->and(json_encode($response->json('props'), JSON_THROW_ON_ERROR))->not->toContain('Hidden needle');
    $this->get('/activity?check=invalid')->assertSessionHasErrors('check');
});

it('loads Activity in batches of 50 and never splits a failure from its recovery across a batch', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['friendly_name' => 'Paired']);
    $paired = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $other = Check::factory()->for($site)->create(['type' => CheckKey::Dns]);
    $newest = [];
    foreach (range(1, 49) as $minute) {
        $newest[] = Event::factory()->for($other)->create(['occurred_at' => now()->subMinutes($minute), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    }
    // Three hours apart: no flap group, so the 50th and 51st groups are the recovery and the failure it ends.
    $recovery = Event::factory()->for($paired)->create(['occurred_at' => now()->subHours(2), 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded]);
    $failure = Event::factory()->for($paired)->create(['occurred_at' => now()->subHours(5), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed]);
    $older = [];
    foreach (range(6, 8) as $hour) {
        $older[] = Event::factory()->for($other)->create(['occurred_at' => now()->subHours($hour), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    }

    $this->actingAs($owner);
    $first = activityPage('/activity?period=7d');
    $batch = $first->json('props.events.data');
    expect($batch)->toHaveCount(50)
        ->and(array_column(array_column($batch, 'latest'), 'id'))->toBe([...array_column($newest, 'id'), $recovery->id])
        ->and($batch[49]['earlier']['id'])->toBe($failure->id)
        ->and($batch[49]['started_at'])->toBe($failure->occurred_at->toIso8601String())
        // The recovery recorded no duration; the pair's three hours stand in, and the check links by its id.
        ->and($batch[49]['latest']['duration_seconds'])->toBe(3 * 3600)
        ->and($batch[49]['latest']['check_id'])->toBe($paired->id)
        ->and($batch[0]['earlier'])->toBeNull()
        // The next batch continues after the merged failure, so it is never shown twice.
        ->and($first->json('scrollProps.events'))->toMatchArray(['pageName' => 'before', 'nextPage' => $failure->id]);

    $next = activityPage('/activity?period=7d&before='.$failure->id, 'events');
    expect(array_column($next->json('props.events.data.*.latest'), 'id'))->toBe(array_column($older, 'id'))
        ->and($next->json('scrollProps.events.nextPage'))->toBeNull()
        ->and($next->json('props'))->not->toHaveKey('clients');

    // A live reload asks for the newest batch only; it pairs the same way.
    $head = activityPage('/activity?period=7d', 'head');
    expect($head->json('props.head.data.49.earlier.id'))->toBe($failure->id)
        ->and($head->json('props'))->not->toHaveKey('events');
});

it('pairs only a recovery of the same check component from the state its failure entered', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->for(Site::factory())->create(['type' => CheckKey::Cron]);
    $task = fn (int $id): array => ['component_type' => SuppressionComponent::Cron, 'component_id' => $id];
    // Another task of the same check recovers: not this failure's recovery.
    Event::factory()->for($check)->create(['occurred_at' => now()->subHours(1), 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded, ...$task(2)]);
    Event::factory()->for($check)->create(['occurred_at' => now()->subHours(4), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed, ...$task(1)]);
    // The same task recovers from Warning, a state its older failure never entered.
    Event::factory()->for($check)->create(['occurred_at' => now()->subHours(8), 'from_result' => CheckResult::Warning, 'to_result' => CheckResult::Succeeded, ...$task(1)]);
    Event::factory()->for($check)->create(['occurred_at' => now()->subHours(12), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed, ...$task(1)]);

    $this->actingAs($owner);
    expect(activityPage('/activity?period=7d')->json('props.events.data.*.earlier'))->toBe([null, null, null, null]);
});

it('starts the timeline over from the newest batch when the buffered changes are applied', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->for(Site::factory())->create(['type' => CheckKey::Dns]);
    $old = [];
    foreach (range(1, 60) as $minute) {
        $old[] = Event::factory()->for($check)->create(['occurred_at' => now()->subHours(3)->subMinutes($minute * 3), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    }
    $this->actingAs($owner);
    $first = activityPage('/activity?period=7d');
    // The reader scrolled one batch further; then a change arrives and the live reload sees it only in `head`.
    activityPage('/activity?period=7d&before='.$first->json('scrollProps.events.nextPage'), 'events');
    $arrived = Event::factory()->for($check)->create(['occurred_at' => now()->subMinute(), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed]);
    expect(activityPage('/activity?period=7d', 'head')->json('props.head.data.0.latest.id'))->toBe($arrived->id);

    // Apply: the client reloads `events` and `head` with the scroll prop reset, from the top of the feed.
    $applied = activityPage('/activity?period=7d', 'events,head', 'events');
    expect(array_column($applied->json('props.events.data.*.latest'), 'id'))->toBe([$arrived->id, ...array_column(array_slice($old, 0, 49), 'id')])
        ->and($applied->json('props.head.data.0.latest.id'))->toBe($arrived->id)
        ->and($applied->json('scrollProps.events'))->toMatchArray(['reset' => true, 'currentPage' => null, 'nextPage' => $old[48]->id]);
});
