<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
});

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
        ->missing('total')->has('events', 1)->where('events.0.latest.id', $changed->id)->where('events.0.event_count', 1)->where('older_cursor', null)->where('newer_cursor', null));
});

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

    $first = $this->actingAs($user)->get('/activity?q=Needle&check=uptime')->assertOk()->inertiaProps();
    expect(array_column(array_column($first['events'], 'latest'), 'id'))->toBe(array_column(array_slice($events, 0, 20), 'id'))
        ->and($first['older_cursor'])->toBe($events[19]->id)->and($first['newer_cursor'])->toBeNull();
    $response = $this->get('/activity?q=Needle&check=uptime&before='.$first['older_cursor'])->assertOk();
    expect(array_column(array_column($response->inertiaProps()['events'], 'latest'), 'id'))->toBe(array_column(array_slice($events, 20), 'id'))
        ->and($response->inertiaProps()['events'][0]['latest']['client_name'])->toBe('Visible')
        ->and($response->inertiaProps()['older_cursor'])->toBeNull()
        ->and($response->inertiaProps()['newer_cursor'])->toBe($events[20]->id)
        ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain('Hidden needle')
        ->and(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain('Hidden needle');
    $this->get('/activity?check=invalid')->assertSessionHasErrors('check');
});
