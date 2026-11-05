<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\CheckRun;
use Heptapod\Models\User;
use Heptapod\Testing\FakeChromiumRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

require_once __DIR__.'/../../addons/lighthouse/tests/Pest.php';

it('excludes unavailable checks without changing observations and presents the capability in UI and API', function (bool $previousError): void {
    config(['heptapod.features.ui_wiring' => true]);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = lighthouseCheck();
    if ($previousError) {
        $fake = new FakeChromiumRenderer;
        lighthouseReply($fake, new ExternalTimeoutException('Browser did not complete.'));
        app()->instance(ChromiumRenderer::class, $fake);
        runLighthouse($check);
    }
    $result = $check->refresh()->latest_run_result->value;
    $counts = array_map(static fn (string $table): int => DB::table($table)->count(), ['check_runs', 'incidents', 'events']);
    Queue::fake([RunCheck::class]);
    Notification::fake();
    config(['heptapod.browser.enabled' => false]);

    $claims = app(ClaimService::class);
    expect($claims->dispatchDue())->toBe(0)->and($claims->dispatchDue())->toBe(0)->and($claims->runNow($check->id, RunOrigin::System))->toBeNull();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
    expect(array_map(static fn (string $table): int => DB::table($table)->count(), ['check_runs', 'incidents', 'events']))->toBe($counts);
    $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('site.state', 'unavailable')->where('check.state', 'unavailable')->where('check.result', $result)
        ->where('check.unavailable_reason', 'lighthouse.browser_unavailable'));
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('tiles.check_errors', 0)->where('tiles.down', 0));
    $token = $user->createToken('availability', ['*'])->plainTextToken;
    $this->withToken($token)->getJson('/api/monitors/'.$check->site_id)->assertOk()
        ->assertJsonPath('checks.0.latest_run_result', $result)->assertJsonPath('checks.0.unavailable_reason', 'Browser service not installed.');

    config(['heptapod.browser.enabled' => true]);
    $fake = new FakeChromiumRenderer;
    lighthouseReply($fake, lighthouseFixture());
    app()->instance(ChromiumRenderer::class, $fake);
    expect(runLighthouse($check)->metrics)->toHaveKeys(['performance_score', 'accessibility_score', 'best_practices_score', 'seo_score']);
    expect(CheckRun::query()->count())->toBe($counts[0] + 1);
})->with(['never run' => false, 'last run errored' => true]);

it('drops a queued claim when its capability disappears before execution', function (): void {
    $check = lighthouseCheck();
    Queue::fake([RunCheck::class]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $token = $check->refresh()->claim_token;
    config(['heptapod.browser.enabled' => false]);
    $job = new RunCheck($check->id, $token, 135, 'checks-browser');

    app()->call($job->handle(...));

    expect(CheckRun::query()->count())->toBe(0)->and($check->refresh()->claim_token)->toBeNull();
    expect(DB::table('incidents')->count())->toBe(0)->and(DB::table('events')->count())->toBe(0);
});
