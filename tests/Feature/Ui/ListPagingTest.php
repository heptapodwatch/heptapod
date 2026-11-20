<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Lists that share their page with other content page through ListPaging: `<list>_page`, `<list>_per_page` and the
// remembered size per list, the same sizes as the major lists (ListPageSizeTest).

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
    $this->withoutVite();
});

it('pages a site overview\'s events newest first and remembers the size', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->for(Site::factory()->for(Client::factory()))->create();
    $events = collect(range(1, 12))->map(fn (int $minute): Event => Event::factory()->for($check)->create([
        'occurred_at' => now()->subMinutes($minute), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Succeeded,
        'type' => 'site.paused']));
    $path = '/sites/'.$check->site_id;

    $this->actingAs($owner)->get($path)->assertInertia(fn (Assert $page): Assert => $page
        ->has('events', 12)->where('events_pagination.total', 12)->where('events_pagination.page_name', 'site_events_page'));
    $this->get($path.'?site_events_per_page=10')->assertCookie(ListPageSize::Cookie, json_encode(['site_events' => 10]), false)
        ->assertInertia(fn (Assert $page): Assert => $page->has('events', 10)->where('events.0.id', $events[0]->id)->where('events_pagination.last_page', 2));

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['site_events' => 10]));
    $this->get($path.'?site_events_page=2')->assertInertia(fn (Assert $page): Assert => $page
        ->has('events', 2)->where('events.0.id', $events[10]->id)->where('events.1.id', $events[11]->id));
    $this->get($path.'?site_events_per_page=7')->assertSessionHasErrors('site_events_per_page');

    // Paging never widens access: another client's member still cannot open the site.
    $this->actingAs(User::factory()->create())->get($path.'?site_events_page=2')->assertForbidden();
});

it('pages one Activity group\'s own events', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->for(Site::factory()->for(Client::factory()))->create();
    // The outbox assigns flap groups when it records a transition; these alternating transitions carry theirs directly.
    $group = null;
    foreach (range(12, 1) as $minute) {
        $failed = $minute % 2 === 0;
        $group ??= Event::factory()->for($check)->create(['occurred_at' => now()->subMinutes($minute),
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed])->id;
        if ($minute < 12) {
            Event::factory()->for($check)->create(['occurred_at' => now()->subMinutes($minute), 'flap_group_id' => $group,
                'from_result' => $failed ? CheckResult::Succeeded : CheckResult::Failed, 'to_result' => $failed ? CheckResult::Failed : CheckResult::Succeeded]);
        }
    }
    $members = Event::query()->where('flap_group_id', $group)->orWhere('id', $group)->orderByDesc('occurred_at')->pluck('id');
    expect($members)->toHaveCount(12);

    $this->actingAs($owner)->get('/activity?group='.$group.'&activity_events_per_page=10')->assertInertia(fn (Assert $page): Assert => $page
        ->has('expanded_events', 10)->where('expanded_events.0.id', $members[0])
        ->where('expanded_pagination.total', 12)->where('expanded_pagination.last_page', 2));
    $this->get('/activity?group='.$group.'&activity_events_page=2&activity_events_per_page=10')->assertInertia(fn (Assert $page): Assert => $page
        ->has('expanded_events', 2)->where('expanded_events.0.id', $members[10])->where('expanded_events.1.id', $members[11]));
});
