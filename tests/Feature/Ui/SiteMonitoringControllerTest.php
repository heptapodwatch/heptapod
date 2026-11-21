<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('resumes a timed pause at tomorrow 08:00 in the viewers timezone and closes its unmonitored period', function (): void {
    app(InstanceSettings::class)->timezone = 'UTC';
    app(InstanceSettings::class)->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $berlin = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $berlin->assignRole('Owner');
    $newYork = User::factory()->create(['timezone' => 'America/New_York']);
    $newYork->assignRole('Owner');
    $site = Site::factory()->create();
    $nySite = Site::factory()->create();

    // 2026-10-05T00:30Z is 2026-10-05 02:30 in Berlin → tomorrow 08:00 Berlin = 2026-10-06T06:00Z.
    $this->actingAs($berlin)->post('/sites/'.$site->id.'/pause', ['until' => 'tomorrow'])->assertRedirect();
    expect($site->refresh()->resume_at?->toIso8601String())->toBe('2026-10-06T06:00:00+00:00');
    // 2026-10-05T00:30Z is 2026-10-04 20:30 in New York → tomorrow 08:00 New York = 2026-10-05T12:00Z.
    $this->actingAs($newYork)->post('/sites/'.$nySite->id.'/pause', ['until' => 'tomorrow'])->assertRedirect();
    expect($nySite->refresh()->resume_at?->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');

    $this->actingAs($berlin)->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('summary.paused', 2));
    $this->artisan('heptapod:resume-due-sites')->assertExitCode(0);
    expect($site->refresh()->paused_at)->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-06T06:00:00Z'));
    $this->artisan('heptapod:resume-due-sites')->assertExitCode(0);
    expect($site->refresh()->paused_at)->toBeNull()->and($site->resume_at)->toBeNull();
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('summary.paused', 0));
    expect(DB::table('unmonitored_periods')->where('site_id', $site->id)->whereNotNull('ended_at')->count())->toBe(1);
});

it('schedules tomorrow 08:00 in the viewers resolved timezone across DST transitions', function (string $at, string $expected, ?string $userTimezone): void {
    app(InstanceSettings::class)->timezone = $userTimezone === null ? 'Europe/Berlin' : 'UTC';
    app(InstanceSettings::class)->save();
    $this->travelTo(CarbonImmutable::parse($at));
    $owner = User::factory()->create(['timezone' => $userTimezone]);
    $owner->assignRole('Owner');
    $site = Site::factory()->create();

    $this->actingAs($owner)->post('/sites/'.$site->id.'/pause', ['until' => 'tomorrow'])->assertRedirect();
    expect($site->refresh()->resume_at?->toIso8601String())->toBe($expected);
})->with([
    'spring forward (own zone)' => ['2026-03-28T22:30:00+00:00', '2026-03-29T06:00:00+00:00', 'Europe/Berlin'],
    'fall back (own zone)' => ['2026-10-24T21:30:00+00:00', '2026-10-25T07:00:00+00:00', 'Europe/Berlin'],
    'inherited instance zone' => ['2026-10-24T21:30:00+00:00', '2026-10-25T07:00:00+00:00', null],
]);

it('cancels timed resume when the client is archived and rejects invalid pause modes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $this->actingAs($user)->post('/sites/'.$site->id.'/pause', ['until' => 'unexpected'])->assertSessionHasErrors('until');
    expect($site->refresh()->paused_at)->toBeNull();
    $this->post('/sites/'.$site->id.'/pause', ['until' => 'hour'])->assertRedirect();
    expect($site->refresh()->resume_at)->not->toBeNull();
    $this->post('/clients/'.$client->id.'/archive')->assertRedirect();
    expect($site->refresh()->resume_at)->toBeNull();
    $this->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('header.id', $site->id)->where('header.client_id', $client->id)->where('can_manage', false)->where('can_move', true));
    $this->travelTo(CarbonImmutable::parse('2026-10-05T02:00:00Z'));
    $this->artisan('heptapod:resume-due-sites')->assertExitCode(0);
    expect($site->refresh()->paused_at)->not->toBeNull();
});
