<?php

declare(strict_types=1);

use Heptapod\Actions\DeactivateMember;
use Heptapod\Contracts\OhDear\OhDearClientFactory;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Jobs\RunOhDearImport;
use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\Client;
use Heptapod\Models\ImportRun;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Testing\RecordedOhDearClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo('2026-11-18 12:00:00 UTC');
    config(['inertia.ssr.enabled' => false]);
    app()->instance(OhDearClientFactory::class, new RecordedOhDearClientFactory(base_path('tests/Fixtures/ohdear/real')));
});

it('plans and imports only approved channels, encrypts secrets, and delivers a test alert', function (): void {
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $client = Client::factory()->create();
    Queue::fake([RunOhDearImport::class]);
    $logged = [];
    Log::listen(static function (MessageLogged $entry) use (&$logged): void {
        $logged[] = [$entry->message, $entry->context];
    });

    $plan = $this->actingAs($owner)->postJson('/settings/import/ohdear/plan', ['ohdear_token' => 'fixture-secret-token', 'sections' => ['channels']])
        ->assertOk()->assertJsonPath('rows', [])->assertJsonPath('remote_error', null);
    $planned = collect($plan->json('channels'))->keyBy('source_id');
    expect($planned[102]['categories'])->toBe(['outage', 'recovery', 'expiring', 'changes']);
    expect($planned[102]['check_types'])->toBe(['uptime', 'certificate_health']);
    expect($planned[102]['notices'])->toContain('import.notice.channel_broader');
    expect($planned[109]['notices'])->toContain('import.notice.channel_webhook');
    expect($planned[116]['notices'])->toBe(['import.notice.channel_invalid']);
    expect($planned[401]['notices'])->toBe(['import.notice.channel_monitor']);
    expect(NotificationDestination::query()->whereNotNull('oh_dear_id')->count())->toBe(0);

    $runId = $plan->json('plan_id');
    $url = $this->postJson('/settings/import/ohdear/runs/'.$runId.'/start', ['expected' => 0, 'groups' => [
        'tag:production' => ['client_id' => $client->id], 'tag:stores' => ['client_id' => $client->id],
    ], 'overrides' => []])->assertCreated()->json('url');
    Queue::assertPushed(RunOhDearImport::class, static fn (RunOhDearImport $job): bool => $job->runId === $runId);
    app()->call([new RunOhDearImport($runId), 'handle']);
    $run = $this->withoutVite()->get($url)->assertOk()->inertiaProps('importRun');
    $results = collect($run['channels'])->keyBy('source_id');
    expect($run['status'])->toBe('completed');
    expect($results[202]['notices'])->toBe(['import.notice.channel_no_client']);
    $channels = NotificationDestination::query()->whereNotNull('oh_dear_id')->orderBy('oh_dear_id')->get()->keyBy('oh_dear_id');
    expect($channels->map->channel->all())->toBe([101 => 'mail', 102 => 'slack', 103 => 'microsoftTeams', 104 => 'pushover', 105 => 'ntfy',
        106 => 'slackWebhook', 107 => 'discordWebhook', 108 => 'googleChat', 109 => 'webhook', 201 => 'mail', 301 => 'mail']);
    expect($channels[101]->enabled)->toBeTrue();
    expect($channels[103]->enabled)->toBeFalse();
    expect($channels[201]->client_id)->toBe($client->id);
    expect($channels[301]->client_id)->toBe($client->id);
    expect($channels[301]->preferences()->get()->keyBy('category.value')['failure']->mode->value)->toBe('immediate');
    expect($channels[301]->preferences()->get()->keyBy('category.value')['outage']->mode->value)->toBe('off');
    expect($channels[102]->destinationConfig()->string('token'))->toBe('xoxp-2...');

    $webhook = $channels[109];
    $raw = DB::table('notification_destinations')->find($webhook->id);
    expect($raw->config.$raw->config_secrets)->not->toContain('fixture-secret')->not->toContain('fixture-health-header-secret');
    $target = 'https://hooks.example/fixture-secret-webhook';
    Http::preventStrayRequests();
    Http::fake([$target => Http::response('', 204)]);
    $this->postJson(route('notification-channels.test', $webhook))->assertOk()->assertJsonPath('status', 'sent');
    Http::assertSent(static fn (Request $request): bool => $request->url() === $target && $request->hasHeader('Authorization', 'fixture-health-header-secret')
        && $request->hasHeader('Signature'));
    $audit = DB::table('activity_log')->pluck('properties')->implode(' ');
    $signingSecret = $webhook->destinationConfig()->string('secret');
    foreach ([$plan->getContent(), json_encode($run, JSON_THROW_ON_ERROR), json_encode($logged, JSON_THROW_ON_ERROR), $audit] as $output) {
        foreach (['fixture-secret', 'fixturesecretpushoverrecipient', 'xoxp-2...', 'fixture-health-header-secret', 'fixture-health-secret', 'fixture-crawler-secret', $signingSecret] as $secret) {
            expect($output)->not->toContain($secret);
        }
    }
});

it('updates the same Oh Dear channels on CLI rerun without printing secrets or silently assigning tags', function (): void {
    $options = ['--token' => 'fixture-secret-import-token', '--only' => 'channels'];
    expect(Artisan::call('heptapod:import-ohdear', [...$options, '--dry-run' => true]))->toBe(0);
    $planOutput = Artisan::output();
    expect($planOutput)->toContain('Assign the tag', 'Adapt the receiver');
    expect(NotificationDestination::query()->whereNotNull('oh_dear_id')->count())->toBe(0);
    expect(Artisan::call('heptapod:import-ohdear', $options))->toBe(0);
    $firstWriteOutput = Artisan::output();
    $channel = NotificationDestination::query()->where('oh_dear_id', 109)->firstOrFail();
    $secret = $channel->destinationConfig()->string('secret');
    $channel->update(['label' => 'Locally edited', 'enabled' => false]);

    expect(Artisan::call('heptapod:import-ohdear', $options))->toBe(0);
    $rerunOutput = Artisan::output();
    expect($rerunOutput)->toContain('Update')->toMatch('/\|\s*201 · Email\s*\|\s*Skipped\s*\|\s*Assign the tag/');
    foreach ([$planOutput, $firstWriteOutput, $rerunOutput] as $output) {
        foreach (['fixture-secret', 'fixturesecretpushoverrecipient', 'xoxp-2...', 'fixture-health-header-secret', 'fixture-health-secret', 'fixture-crawler-secret', $secret] as $credential) {
            expect($output)->not->toContain($credential);
        }
    }
    $channel->refresh();
    expect($channel->enabled)->toBeTrue();
    expect($channel->label)->not->toBe('Locally edited');
    expect($channel->destinationConfig()->string('secret'))->toBe($secret);
    expect(NotificationDestination::query()->whereNotNull('oh_dear_id')->count())->toBe(9);
});

it('uses the fallback locale for team channels imported with an unsupported process locale', function (): void {
    config(['app.fallback_locale' => 'de']);
    app()->setLocale('en_US');

    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-secret-import-token', '--only' => 'channels']))->toBe(0);

    expect(NotificationDestination::query()->where('oh_dear_id', 101)->firstOrFail()->locale)->toBe('de');
});

it('writes no further channel once the starter of a channels-only import loses access', function (): void {
    $starter = User::factory()->owner()->create(['locale' => 'en']);
    $otherOwner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    Queue::fake([RunOhDearImport::class, TerminateLiveConnections::class]);
    $plan = $this->actingAs($starter)->postJson('/settings/import/ohdear/plan', ['ohdear_token' => 'fixture-secret-token', 'sections' => ['channels']])->assertOk();
    $runId = $plan->json('plan_id');
    $this->postJson('/settings/import/ohdear/runs/'.$runId.'/start', ['expected' => 0, 'groups' => [
        'tag:production' => ['client_id' => $client->id], 'tag:stores' => ['client_id' => $client->id],
    ], 'overrides' => []])->assertCreated();
    // Removed from the team as soon as the first channel is written.
    $remove = static fn () => app(DeactivateMember::class)($otherOwner, $starter);
    NotificationDestination::created(static function (NotificationDestination $channel) use (&$remove): void {
        if ($channel->oh_dear_id !== null && $remove !== null) {
            [$removeNow, $remove] = [$remove, null];
            $removeNow();
        }
    });

    app()->call([new RunOhDearImport($runId), 'handle']);

    $run = ImportRun::query()->findOrFail($runId);
    expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->error)->toBe('access_revoked')
        ->and(NotificationDestination::query()->whereNotNull('oh_dear_id')->count())->toBe(1);
});
