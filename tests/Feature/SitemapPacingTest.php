<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Sleep;

/*
 * Per-host pacing is a dispatch contract: request starts to one host are at least 110 ms plus the speed delay apart.
 * The paced client reads the Carbon clock and waits through Sleep, so a frozen clock with Sleep synced to Carbon
 * records every dispatch instant exactly, without sockets or wall-clock comparison.
 */
beforeEach(function (): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
});

/**
 * Runs the real sitemap check through the registry and recorder; the fake HTTP seam records each dispatch on the test clock.
 *
 * @param  list<string>  $urls
 * @param  array<string, string>  $config
 * @return array{run: CheckRun, context: CheckContext, dispatches: list<array{host: string, method: string, at: float}>, finishedAt: CarbonImmutable}
 */
function runPacedSitemap(array $urls, array $config, ?float $budgetSeconds = null): array
{
    $dispatches = [];
    $body = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', array_map(static fn (string $url): string => '<url><loc>'.$url.'</loc></url>', $urls)).'</urlset>';
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use (&$dispatches, $body): FetchResult {
        $dispatches[] = ['host' => (string) parse_url($request->url, PHP_URL_HOST), 'method' => $request->method, 'at' => CarbonImmutable::now()->getPreciseTimestamp()];

        return $request->url === 'http://fixtures.test/sitemap.xml' ? new FetchResult(200, $body) : new FetchResult(200);
    }));
    $site = Site::factory()->create(['url' => 'http://fixtures.test']);
    $sitemap = app(CheckRegistry::class)->get(CheckKey::Sitemap);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Sitemap, 'config' => $sitemap->definition()->configSchema->validateConfig($config)]);
    $context = ClaimedRun::start($check);
    if ($budgetSeconds !== null) {
        $context = new CheckContext($context->siteId, $context->checkId, $context->runId, $context->claimToken, $context->url, $context->startedAt,
            $context->startedAt->addMilliseconds((int) ($budgetSeconds * 1000)), $context->config, $context->crawlerHeaders, $context->previousResult);
    }

    $outcome = $sitemap->execute($context);
    $finishedAt = CarbonImmutable::now();
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $finishedAt, $outcome))->accepted)->toBeTrue();

    return ['run' => CheckRun::query()->where('check_id', $check->id)->sole(), 'context' => $context, 'dispatches' => $dispatches, 'finishedAt' => $finishedAt];
}

/**
 * @param  list<array{host: string, method: string, at: float}>  $dispatches
 * @return list<float> Microseconds between consecutive request starts to the given host.
 */
function sameHostDispatchGaps(array $dispatches, string $host): array
{
    $starts = array_column(array_values(array_filter($dispatches, static fn (array $dispatch): bool => $dispatch['host'] === $host)), 'at');
    $gaps = [];
    for ($i = 1, $count = count($starts); $i < $count; $i++) {
        $gaps[] = $starts[$i] - $starts[$i - 1];
    }

    return $gaps;
}

/** @return list<string> */
function pacedSitemapPages(string $host, int $count): array
{
    return array_map(static fn (int $index): string => 'http://'.$host.'/page-'.$index, range(1, $count));
}

it('spaces every request start to one host by 110 ms plus the speed delay and probes at most 500 URLs within the 300 second budget', function (string $speed, int $intervalMilliseconds): void {
    $result = runPacedSitemap(pacedSitemapPages('fixtures.test', 501), ['speed' => $speed]);
    $run = $result['run'];
    $heads = array_filter($result['dispatches'], static fn (array $dispatch): bool => $dispatch['method'] === 'HEAD');
    $gaps = sameHostDispatchGaps($result['dispatches'], 'fixtures.test');

    expect($result['context']->startedAt->diffInSeconds($result['context']->deadline))->toEqual(300)
        ->and($run->result)->toBe(RunResult::Succeeded)
        ->and($run->evidence['totalUrlCount'])->toBe(501)
        ->and($heads)->toHaveCount(500)
        ->and($gaps)->toHaveCount(500)
        ->and(min($gaps))->toBeGreaterThanOrEqual($intervalMilliseconds * 1000.0)
        ->and($result['finishedAt']->lessThanOrEqualTo($result['context']->deadline))->toBeTrue();
})->with([
    'default' => ['default', 135],
    'slowest' => ['slowest', 210],
    'fastest' => ['fastest', 110],
]);

it('paces each host on its own gate so a second host does not wait behind the first', function (): void {
    $urls = [];
    foreach (range(1, 10) as $index) {
        $urls[] = 'http://fixtures.test/page-'.$index;
        $urls[] = 'http://external.fixtures.test/page-'.$index;
    }
    $result = runPacedSitemap($urls, ['speed' => 'fastest']);
    $heads = array_values(array_filter($result['dispatches'], static fn (array $dispatch): bool => $dispatch['method'] === 'HEAD'));
    $starts = array_column($heads, 'at');
    $crossHostGaps = [];
    for ($i = 1, $count = count($heads); $i < $count; $i++) {
        if ($heads[$i]['host'] !== $heads[$i - 1]['host']) {
            $crossHostGaps[] = $heads[$i]['at'] - $heads[$i - 1]['at'];
        }
    }

    expect($result['run']->result)->toBe(RunResult::Succeeded)
        ->and($heads)->toHaveCount(20)
        ->and(min(sameHostDispatchGaps($heads, 'fixtures.test')))->toBeGreaterThanOrEqual(110000.0)
        ->and(min(sameHostDispatchGaps($heads, 'external.fixtures.test')))->toBeGreaterThanOrEqual(110000.0)
        ->and(min($crossHostGaps))->toBeLessThan(110000.0)
        // The site host's ten probes follow its sitemap GET, so they span ten intervals; one shared gate would need twenty.
        ->and(max($starts) - min($starts))->toBeLessThanOrEqual(10 * 110000.0);
});

it('succeeds when the paced probes fit the budget and errors with truthful progress when admission runs out', function (float $budget, RunResult $expected): void {
    $result = runPacedSitemap(pacedSitemapPages('fixtures.test', 20), ['speed' => 'fastest'], $budget);
    $run = $result['run'];
    $heads = array_filter($result['dispatches'], static fn (array $dispatch): bool => $dispatch['method'] === 'HEAD');
    $lastAdmission = $result['context']->deadline->getPreciseTimestamp() - 1500000;

    expect($run->result)->toBe($expected)
        ->and($result['finishedAt']->lessThanOrEqualTo($result['context']->deadline))->toBeTrue();
    if ($result['dispatches'] !== []) {
        expect(min(sameHostDispatchGaps($result['dispatches'], 'fixtures.test') ?: [INF]))->toBeGreaterThanOrEqual(110000.0)
            ->and(max(array_column($result['dispatches'], 'at')))->toBeLessThanOrEqual($lastAdmission);
    }
    if ($expected === RunResult::Succeeded) {
        expect($heads)->toHaveCount(20)
            ->and($run->metrics)->toEqual(['totalIssuesCount' => 0, 'totalUrlCount' => 20, 'hasIssues' => false])
            ->and($run->evidence['sitemaps'][0]['checkedReachabilityOfAllUrls'])->toBeTrue();

        return;
    }
    $total = $result['dispatches'] === [] ? 0 : 20;
    expect($run->summary)->toBe('sitemap.incomplete_time_limit_progress')
        ->and($run->summary_params)->toEqual(['checked' => count($heads), 'total' => $total])
        ->and(count($heads))->toBeLessThan(20)
        ->and($run->metrics)->toBeNull()
        ->and(array_column($run->evidence['issues'], 'type'))->toBe(['sitemap.issue.time_limit'])
        ->and(Incident::where('check_id', $run->check_id)->count())->toBe(0);
    if ($total > 0) {
        expect($run->evidence['sitemaps'][0]['checkedReachabilityOfAllUrls'])->toBeFalse();
    }
})->with([
    'fits-the-budget' => [10.0, RunResult::Succeeded],
    'admission-runs-out' => [2.5, RunResult::Errored],
    'no-usable-budget' => [0.9, RunResult::Errored],
]);
