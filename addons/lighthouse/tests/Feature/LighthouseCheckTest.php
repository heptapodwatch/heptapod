<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Lighthouse\LighthousePersistence;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\LighthouseReport;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Event;
use Heptapod\Testing\FakeChromiumRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OhDear\PhpSdk\Dto\LighthouseReport as SdkReport;

require_once __DIR__.'/../Pest.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

it('records real mobile and desktop reports through a seam swap after registry resolution without fabricating removed metrics', function (string $device, float $interactive): void {
    app(CheckRegistry::class)->get(CheckKey::Lighthouse);
    $check = lighthouseCheck(['device_emulation' => $device]);
    $raw = (array) json_decode(file_get_contents(__DIR__.'/../Fixtures/'.$device.'.json'), flags: JSON_THROW_ON_ERROR);
    $fake = new FakeChromiumRenderer;
    lighthouseReply($fake, $raw, $device === 'desktop');
    app()->instance(ChromiumRenderer::class, $fake);

    $run = runLighthouse($check);

    expect($run->result)->toBe(RunResult::Succeeded)
        ->and($run->metrics['accessibility_score'])->toBe(96)
        ->and($run->metrics['seo_score'])->toBe(80)
        ->and($run->metrics['progressive_web_app_score'])->toBeNull()
        ->and($run->metrics['time_to_interactive_in_ms'])->toEqual($interactive)
        ->and($run->metrics['first_contentful_paint_in_ms'])->toBeGreaterThan(0);
    $stored = DB::table('lighthouse_reports')->sole();
    expect(json_decode(gzdecode(base64_decode($stored->compressed_report, true)), flags: JSON_THROW_ON_ERROR))->toEqual((object) $raw);
    $sdk = SdkReport::fromResponse([...$run->metrics, 'id' => $stored->id, 'created_at' => $stored->created_at]);
    expect($sdk->accessibilityScore)->toBe(96)->and($sdk->timeToInteractiveInMs)->toBe($interactive);
    expect(DB::table('lighthouse_baselines')->where('check_id', $check->id)->value('run_id'))->toBe($run->id);
})->with(['mobile' => ['mobile', 787.2195000000002], 'desktop' => ['desktop', 300.0]]);

it('compares only accepted scores and records each below and above crossing once even at unchanged warning severity', function (): void {
    $check = lighthouseCheck(['notification_settings' => ['performance' => 90, 'seo' => 90]]);
    $fake = new FakeChromiumRenderer;
    app()->instance(ChromiumRenderer::class, $fake);
    $a = lighthouseFixture();
    $a['categories']['performance']['score'] = 0.95;
    lighthouseReply($fake, $a);
    expect(runLighthouse($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'lighthouse.score_threshold_crossed')->count())->toBe(0);
    lighthouseReply($fake, new ExternalTimeoutException('Service unavailable'));
    expect(runLighthouse($check)->result)->toBe(RunResult::Errored);
    $store = app(LighthousePersistence::class);
    $store->prune(CarbonImmutable::now()->addSecond());
    expect(DB::table('lighthouse_reports')->count())->toBe(0);

    $b = $a;
    $b['categories']['performance']['score'] = 0.80;
    lighthouseReply($fake, $b);
    $changed = runLighthouse($check);
    expect($changed->result)->toBe(RunResult::Warning)
        ->and($changed->evidence['score_drops'])->toEqual([['category' => 'performance', 'previousScore' => 95, 'actualScore' => 80]]);
    $event = Event::query()->where('type', 'lighthouse.score_threshold_crossed')->sole();
    expect($event->payload)->toEqual(['category' => 'performance', 'direction' => 'below', 'previousScore' => 95, 'actualScore' => 80, 'notificationThreshold' => 90,
        'run_id' => $changed->id, 'run_ended_at' => '2026-10-05T12:00:00.000000+00:00',
        'summary' => 'lighthouse.warning', 'summary_params' => []]);
    runLighthouse($check);
    expect(Event::query()->where('type', 'lighthouse.score_threshold_crossed')->count())->toBe(1);
    lighthouseReply($fake, $a);
    expect(runLighthouse($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'lighthouse.score_threshold_crossed')->orderBy('id')->get()->last()->payload['direction'])->toBe('above');
    lighthouseReply($fake, $b);
    runLighthouse($check);
    expect(Event::query()->where('type', 'lighthouse.score_threshold_crossed')->count())->toBe(3);
});

it('warns at a ten point drop and at strictly below a threshold including genuine zero scores', function (int $score, array $settings, RunResult $expected): void {
    $check = lighthouseCheck(['notification_settings' => $settings]);
    $fake = new FakeChromiumRenderer;
    app()->instance(ChromiumRenderer::class, $fake);
    $raw = lighthouseFixture();
    lighthouseReply($fake, $raw);
    runLighthouse($check);
    $raw['categories']['performance']['score'] = $score / 100;
    lighthouseReply($fake, $raw);

    $run = runLighthouse($check);

    expect($run->result)->toBe($expected)->and($run->metrics['performance_score'])->toBe($score);
})->with([
    'ten inclusive' => [90, [], RunResult::Warning],
    'nine points' => [91, [], RunResult::Succeeded],
    'threshold equal' => [91, ['performance' => 91], RunResult::Succeeded],
    'one below threshold' => [91, ['performance' => 92], RunResult::Warning],
    'zero' => [0, ['performance' => null], RunResult::Warning],
    'rounded rather than float-truncated' => [29, [], RunResult::Warning],
]);

it('records malformed or unavailable audits as errors with no zero samples or published report', function (string $problem): void {
    $check = lighthouseCheck();
    $raw = lighthouseFixture();
    match ($problem) {
        'runtime' => $raw['runtimeError'] = ['code' => 'NO_FCP', 'message' => 'Unavailable'],
        'null category' => $raw['categories']['performance']['score'] = null,
        'missing category' => $raw['categories'] = [],
        'bad metric' => $raw['audits']['first-contentful-paint']['numericValue'] = 'not numeric',
        'out of range' => $raw['categories']['performance']['score'] = 1.1,
    };
    $fake = new FakeChromiumRenderer;
    lighthouseReply($fake, $raw);
    app()->instance(ChromiumRenderer::class, $fake);

    $run = runLighthouse($check);

    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    expect(DB::table('lighthouse_reports')->count())->toBe(0)->and(DB::table('lighthouse_baselines')->count())->toBe(0);
})->with(['runtime', 'null category', 'missing category', 'bad metric', 'out of range']);

it('round trips open settings and ordered encrypted headers but sends one native header object and scrubs the stored report', function (): void {
    $config = ['continent' => 'Europe', 'device_emulation' => 'desktop', 'cpu_slowdown_modifier' => 2.5,
        'notification_settings' => ['performance' => null, 'future' => ['enabled' => true]],
        'http_client_headers' => [['name' => 'X-Key', 'value' => 'first-secret'], ['name' => 'x-key', 'value' => 'final-secret']]];
    $check = lighthouseCheck($config);
    $check->site->update(['crawler_headers' => [['name' => 'X-Key', 'value' => 'site-secret'], ['name' => 'X-Site', 'value' => 'site-only-secret']]]);
    expect($check->fresh()->executionConfig())->toEqual($config);
    expect($check->fresh()->executionConfig()['http_client_headers'])->toBe($config['http_client_headers']);
    expect(DB::table('checks')->where('id', $check->id)->value('config'))->not->toContain('first-secret', 'final-secret');
    $raw = lighthouseFixture('desktop');
    $raw['configSettings']['extraHeaders'] = ['x-key' => 'final-secret', 'X-Site' => 'site-only-secret', 'Unexpected' => 'unknown-returned-header'];
    $raw['requestedUrl'] = 'https://example.com/?token=final-secret';
    $raw['runWarnings'][] = 'site-secret / first-secret / site-only-secret';
    $fake = (new FakeChromiumRenderer)->respondWithLighthouse('https://example.com/', new LighthouseReport($raw, 'https://example.com/'),
        ['desktop' => true, 'cpuSlowdownMultiplier' => 2.5, 'extraHeaders' => (object) ['x-key' => 'final-secret', 'X-Site' => 'site-only-secret']]);
    app()->instance(ChromiumRenderer::class, $fake);

    $run = runLighthouse($check);

    expect($run->result)->toBe(RunResult::Succeeded);
    $json = gzdecode(base64_decode(DB::table('lighthouse_reports')->value('compressed_report'), true));
    expect($json)->not->toContain('final-secret', 'first-secret', 'site-secret', 'site-only-secret', 'unknown-returned-header')->toContain('[redacted]');
    expect(json_encode([$run->metrics, $run->evidence, Event::query()->pluck('payload')]))->not->toContain('final-secret', 'site-only-secret');
});

it('keeps absent metrics null and preserves an available zero PWA score', function (): void {
    $raw = lighthouseFixture();
    unset($raw['audits']['interactive'], $raw['audits']['speed-index']);
    $raw['categories']['pwa'] = ['score' => 0];
    $fake = new FakeChromiumRenderer;
    lighthouseReply($fake, $raw);
    app()->instance(ChromiumRenderer::class, $fake);

    $run = runLighthouse(lighthouseCheck(['notification_settings' => null]));

    expect($run->result)->toBe(RunResult::Succeeded)
        ->and($run->metrics['time_to_interactive_in_ms'])->toBeNull()
        ->and($run->metrics['speed_index_in_ms'])->toBeNull()
        ->and($run->metrics['progressive_web_app_score'])->toBe(0);
});

it('changes warning state but emits no crossing for a settings-only edit', function (): void {
    $check = lighthouseCheck(['notification_settings' => ['seo' => 70]]);
    $fake = new FakeChromiumRenderer;
    lighthouseReply($fake, lighthouseFixture());
    app()->instance(ChromiumRenderer::class, $fake);
    expect(runLighthouse($check)->result)->toBe(RunResult::Succeeded);
    $schema = app(CheckRegistry::class)->get(CheckKey::Lighthouse)->definition()->configSchema;
    $check->update(['config' => $schema->validateConfig(['notification_settings' => ['seo' => 90]])]);

    expect(runLighthouse($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'lighthouse.score_threshold_crossed')->count())->toBe(0);
});

it('rejects invalid Lighthouse configuration at the schema boundary', function (array $config): void {
    expect(fn () => app(CheckRegistry::class)->get(CheckKey::Lighthouse)->definition()->configSchema->validateConfig($config))->toThrow(ValidationException::class);
})->with([
    [['device_emulation' => 'tablet']], [['cpu_slowdown_modifier' => 0]], [['cpu_slowdown_modifier' => 21]],
    [['notification_settings' => ['performance' => 101]]], [['notification_settings' => ['seo' => '90']]],
    [['http_client_headers' => [['name' => 'X-Bad', 'value' => "bad\r\nheader"]]]], [['unrecognized' => true]],
]);

it('fences publication by token and rolls back reports and baselines with the recorder transaction', function (): void {
    $check = lighthouseCheck();
    $fake = new FakeChromiumRenderer;
    lighthouseReply($fake, lighthouseFixture());
    app()->instance(ChromiumRenderer::class, $fake);
    $at = CarbonImmutable::parse('2026-10-02T00:00:00.123456Z');
    $runId = (int) DB::scalar("SELECT nextval('check_runs_id_seq')");
    $token = (string) Str::uuid();
    $other = (string) Str::uuid();
    $context = new CheckContext($check->site_id, $check->id, $runId, $token, $check->site->url, $at, $at->addSeconds(135), [], [], null);
    $type = app(CheckRegistry::class)->get(CheckKey::Lighthouse);
    $type->execute($context);
    $type->execute(new CheckContext($context->siteId, $context->checkId, $runId, $other, $context->url, $at, $context->deadline, [], [], null));
    $store = $type->persistence();
    $reference = new RunReference($runId, $at);
    expect(fn () => DB::transaction(function () use ($store, $reference, $token): void {
        $store->finalize($reference, $token);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');
    expect(DB::table('lighthouse_baselines')->count())->toBe(0)->and(DB::table('lighthouse_reports')->whereNotNull('run_ended_at')->count())->toBe(0);
    $store->finalize($reference, (string) Str::uuid());
    $store->discard($runId, $token);
    $store->discard($runId, $token);
    $store->finalize($reference, $other);
    $store->finalize($reference, $other);
    $store->discard($runId, $other);
    $acceptedContext = new CheckContext($context->siteId, $context->checkId, $runId, $other, $context->url, $at, $context->deadline, [], [], null);
    expect(fn () => $type->execute($acceptedContext))->toThrow(LogicException::class, 'Cannot stage an accepted');
    expect(fn () => $store->finalize(new RunReference($runId, $at->addSecond()), $other))->toThrow(LogicException::class, 'different run timestamp');
    expect(DB::table('lighthouse_reports')->count())->toBe(1)
        ->and(DB::table('lighthouse_reports')->value('claim_token'))->toBe($other)
        ->and(DB::table('lighthouse_reports')->value('run_ended_at'))->toContain('.123456');
    $store->prune($at);
    expect(DB::table('lighthouse_reports')->count())->toBe(1);
    $store->prune($at->addMicrosecond());
    expect(DB::table('lighthouse_reports')->count())->toBe(0)->and($store->baseline($check->id)['run_id'])->toBe($runId);
    expect(fn () => $store->stage($context, 'other', [[]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $store->stage($context, 'report', []))->toThrow(InvalidArgumentException::class);
});
