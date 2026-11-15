<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    // A sub-second clock: the page reloaded right after a snooze must show it within the same second.
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00.500000Z'));
});

function memberOf(Client $client): User
{
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);

    return $member;
}

it('lets a member snooze a check from its page and end the snooze, keeping the failed result in the Sites list', function (): void {
    $client = Client::factory()->create();
    $check = Check::factory()->for(Site::factory()->for($client))->create(['type' => CheckKey::Sitemap, 'latest_run_result' => CheckResult::Failed]);
    $page = "/sites/{$check->site_id}/checks/{$check->id}";
    $member = memberOf($client);

    $this->actingAs($member)->from($page)->post("{$page}/snooze", ['minutes' => 60])->assertRedirect($page);
    $this->get('/sites')->assertInertia(fn (Assert $sites): Assert => $sites->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
        ->where('sites.0.checks.0.snoozed_until', '2026-10-03T13:00:00.500000Z')->where('sites.0.checks.0.state', 'failed')));

    $this->from($page)->post("{$page}/snooze", ['snooze_until' => '2026-10-06T09:00:00+02:00'])->assertRedirect($page);
    $this->get('/sites')->assertInertia(fn (Assert $sites): Assert => $sites->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
        ->where('sites.0.checks.0.snoozed_until', '2026-10-06T07:00:00.000000Z')));

    $this->from($page)->delete("{$page}/snooze")->assertRedirect($page);
    $this->get('/sites')->assertInertia(fn (Assert $sites): Assert => $sites->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
        ->where('sites.0.checks.0.snoozed_until', null)));
});

it('refuses a snooze end in the past and a check of a site the member cannot see', function (): void {
    $client = Client::factory()->create();
    $check = Check::factory()->for(Site::factory()->for($client))->create();
    $hidden = Check::factory()->create();
    $page = "/sites/{$check->site_id}/checks/{$check->id}";
    $member = memberOf($client);

    $this->actingAs($member)->from($page)->post("{$page}/snooze", ['snooze_until' => '2026-10-03T11:00:00Z'])
        ->assertRedirect($page)->assertSessionHasErrors(['snooze_until' => __('check_snooze.until_invalid')]);
    $this->post("/sites/{$hidden->site_id}/checks/{$hidden->id}/snooze", ['minutes' => 60])->assertNotFound();
    expect($check->snoozes()->count() + $hidden->snoozes()->count())->toBe(0);
});

it('lets only an owner or admin turn a check off, which reads Off and not Paused in the Sites list', function (): void {
    $client = Client::factory()->create();
    $check = Check::factory()->for(Site::factory()->for($client))->create(['type' => CheckKey::Sitemap, 'latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create();
    $path = "/sites/{$check->site_id}/checks/{$check->id}/disable";

    $this->actingAs(memberOf($client))->post($path)->assertForbidden();
    expect($check->refresh()->enabled)->toBeTrue();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin)->post($path)->assertRedirect("/sites/{$check->site_id}/checks/{$check->id}");
    expect($check->refresh()->enabled)->toBeFalse()->and($incident->refresh()->resolved_at)->not->toBeNull();
    $this->get('/sites')->assertInertia(fn (Assert $sites): Assert => $sites->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
        ->where('sites.0.checks.0.enabled', false)->where('sites.0.checks.0.state', 'disabled')));
});
