<?php

declare(strict_types=1);

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\LighthouseReport;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Site;
use Heptapod\Testing\FakeChromiumRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__DIR__.'/Feature', __DIR__.'/Manual');

/** @return array<string, mixed> */
function lighthouseFixture(string $device = 'mobile'): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/'.$device.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $config */
function lighthouseCheck(array $config = []): Check
{
    $site = Site::factory()->create(['url' => 'https://example.com/']);
    $definition = app(CheckRegistry::class)->get(CheckKey::Lighthouse)->definition();

    return Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Lighthouse,
        'interval_seconds' => $definition->defaultInterval, 'config' => $definition->configSchema->validateConfig($config)]);
}

function runLighthouse(Check $check): CheckRun
{
    Queue::fake([RunCheck::class]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $definition = app(CheckRegistry::class)->get(CheckKey::Lighthouse)->definition();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    app()->call($job->handle(...));
    app()->call($job->handle(...));

    return CheckRun::query()->orderByDesc('id')->firstOrFail();
}

/** @param array<string, mixed>|ExternalTimeoutException $reply */
function lighthouseReply(FakeChromiumRenderer $fake, array|ExternalTimeoutException $reply, bool $desktop = false, array $headers = []): void
{
    $fake->respondWithLighthouse('https://example.com/', is_array($reply) ? new LighthouseReport($reply, 'https://example.com/') : $reply,
        ['desktop' => $desktop, 'cpuSlowdownMultiplier' => $desktop ? 1 : 4, 'extraHeaders' => (object) $headers]);
}
