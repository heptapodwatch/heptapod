<?php

declare(strict_types=1);

use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\User;
use Heptapod\Testing\FakeChromiumRenderer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Pest.php';

it('projects the current accepted evidence and scores, never the retained comparison baseline', function (): void {
    config(['heptapod.features.ui_wiring' => true]);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = lighthouseCheck(['notification_settings' => ['performance' => 70]]);
    $fake = new FakeChromiumRenderer;
    app()->instance(ChromiumRenderer::class, $fake);
    $raw = lighthouseFixture();
    $raw['categories']['performance']['score'] = 0.4;
    lighthouseReply($fake, $raw);
    expect(runLighthouse($check)->result)->toBe(RunResult::Warning);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.check_type', 'lighthouse')->where('detail.metrics.performance_score', 40)
        ->where('detail.evidence.issues', [['category' => 'performance', 'actualScore' => 40, 'notificationThreshold' => 70]]));

    lighthouseReply($fake, new ExternalTimeoutException('Browser did not complete.'));
    expect(runLighthouse($check)->result)->toBe(RunResult::Errored);
    expect(json_decode(DB::table('lighthouse_baselines')->value('scores'), true)['performance'])->toBe(40);
    $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.result', 'errored-or-timed-out')->where('detail.metrics', null)->where('detail.evidence', null));

    $raw['categories']['performance']['score'] = 0.8;
    lighthouseReply($fake, $raw);
    expect(runLighthouse($check)->result)->toBe(RunResult::Succeeded);
    $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.metrics.performance_score', 80)->where('detail.evidence.issues', [])->where('detail.evidence.score_drops', []));
});
