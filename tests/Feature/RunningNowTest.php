<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Enums\ClientScope;
use Heptapod\Events\LiveSignal;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\Event as FrameworkEvent;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
    Queue::fake();
});

function runningNowUser(string $role, ClientScope $scope = ClientScope::All): User
{
    $user = User::factory()->create(['client_scope' => $scope]);
    $user->assignRole($role);

    return $user;
}

function finishQueuedRun(Check $check): void
{
    $definition = app(CheckRegistry::class)->get($check->type)->definition();
    app()->call(new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue)->handle(...));
}

it('shares manual runs the viewer may see, own first, and never scheduled ones', function (): void {
    $owner = runningNowUser('Owner');
    $admin = runningNowUser('Admin');
    [$first, $second, $scheduled] = Check::factory()->count(3)->create();
    $this->actingAs($admin)->post("/sites/{$first->site_id}/checks/{$first->id}/run")->assertRedirect();
    $this->travel(1)->seconds();
    $this->actingAs($owner)->post("/sites/{$second->site_id}/checks/{$second->id}/run")->assertRedirect();
    expect(app(ClaimService::class)->runNow($scheduled->id, RunOrigin::Scheduled))->not->toBeNull();

    $this->actingAs($owner)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->where('runningNow.active_check_runs', 2)
        ->has('runningNow.check_runs', 2)
        ->where('runningNow.check_runs.0.check_id', $second->id)
        ->where('runningNow.check_runs.0.mine', true)
        ->where('runningNow.check_runs.0.run.phase', 'queued')
        ->where('runningNow.check_runs.1.check_id', $first->id)
        ->where('runningNow.check_runs.1.mine', false)
        ->where('runningNow.check_runs.1.run.actor', $admin->name));
});

it('keeps another client\'s manual runs from a restricted Member', function (): void {
    $owner = runningNowUser('Owner');
    $visible = Check::factory()->create();
    $hidden = Check::factory()->create();
    $member = runningNowUser('Member', ClientScope::Restricted);
    $member->clients()->attach(Site::findOrFail($visible->site_id)->client_id);
    foreach ([$visible, $hidden] as $check) {
        $this->actingAs($owner)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
    }

    $this->actingAs($member)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->where('runningNow.active_check_runs', 1)
        ->has('runningNow.check_runs', 1)
        ->where('runningNow.check_runs.0.check_id', $visible->id)
        ->where('runningNow.check_runs.0.mine', false));
});

it('keeps the viewer\'s own finished result for a minute and nobody else\'s', function (): void {
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (): FetchResult => new FetchResult(200, 'ok')));
    $owner = runningNowUser('Owner');
    $admin = runningNowUser('Admin');
    $check = Check::factory()->create();
    $this->actingAs($owner)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
    FrameworkEvent::fake([LiveSignal::class]);
    finishQueuedRun($check);
    FrameworkEvent::assertDispatched(LiveSignal::class, static fn (LiveSignal $signal): bool => $signal->topic === 'run.finished'
        && $signal->broadcastWith()['run']['origin'] === 'manual');

    $this->actingAs($owner)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->where('runningNow.active_check_runs', 0)
        ->has('runningNow.check_runs', 1)
        ->where('runningNow.check_runs.0.mine', true)
        ->where('runningNow.check_runs.0.result', 'succeeded'));
    $this->actingAs($admin)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page->has('runningNow.check_runs', 0));
    $this->travel(61)->seconds();
    $this->actingAs($owner)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page->has('runningNow.check_runs', 0));
});
