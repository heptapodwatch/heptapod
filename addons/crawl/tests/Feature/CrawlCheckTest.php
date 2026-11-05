<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Heptapod\Addons\Crawl\CrawlArtifact;
use Heptapod\Addons\Crawl\CrawlCollector;
use Heptapod\Addons\Crawl\CrawlMetrics;
use Heptapod\Addons\Crawl\CrawlPersistence;
use Heptapod\Addons\Crawl\CrawlTargetUnavailable;
use Heptapod\Addons\Crawl\MixedContentFinding;
use Heptapod\Addons\Crawl\NotCrawlableReason;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\PublicRequestProfile;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetAddressScope;
use Heptapod\Contracts\PreviousResult;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Jobs\RefreshSiteIcon;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Psr\Http\Message\RequestInterface;
use Spatie\LaravelData\Exceptions\CannotCastEnum;
use Symfony\Component\Process\Process;
use Tests\Support\ClosedGuardedHttp;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
});

/** @param array<string, mixed> $config */
function crawlCheck(CheckKey $key = CheckKey::BrokenLinks, string $url = 'https://fixtures.test/crawl-addon/page', array $config = []): Check
{
    $site = Site::factory()->create(['url' => $url]);

    return Check::factory()->create(['site_id' => $site->id, 'type' => $key, 'enabled' => true, 'config' => app(CheckRegistry::class)->get($key)->definition()->configSchema->validateConfig($config)]);
}

/** @param list<array{name: string, value: string}> $headers */
function crawlContext(Check $check, array $headers = []): CheckContext
{
    $context = ClaimedRun::start($check, headers: $headers);

    return new CheckContext($context->siteId, $context->checkId, $context->runId, $context->claimToken, $context->url,
        $context->startedAt, CarbonImmutable::createFromTimestamp(microtime(true), 'UTC')->addSeconds(300),
        $context->config, $context->crawlerHeaders, $context->previousResult);

}

/** @param array<string, list<string>> $headers */
function crawlHttpRequest(string $url, array $headers = [], string $siteHost = 'fixtures.test'): FetchRequest
{
    return new FetchRequest(
        $url,
        headers: [...$headers, ...array_map(static fn (string $value): array => [$value], PublicRequestProfile::headers()), 'Host' => [new GuzzleRequest('GET', $url)->getHeaderLine('Host')]],
        body: '',
        maxWireBytes: config('heptapod.crawl.max_response_bytes'),
        maxDecodedBytes: config('heptapod.crawl.max_response_bytes'),
        redirects: RedirectPolicy::Untrusted,
        siteHost: $siteHost,
        timeoutSeconds: config('heptapod.crawl.request_timeout_seconds'),
        acceptedStatuses: range(200, 599),
    );
}

function crawlHeadRequest(string $url): FetchRequest
{
    return new FetchRequest($url, method: 'HEAD', maxWireBytes: config('heptapod.crawl.max_response_bytes'), maxDecodedBytes: config('heptapod.crawl.max_response_bytes'), redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test', timeoutSeconds: config('heptapod.crawl.request_timeout_seconds'), refusalIsFinding: true);
}

/** @param list<Response> $responses
 * @param  list<RequestInterface>  $sent
 */
function crawlMockTransport(array $responses, array &$sent): MockHandler
{
    return new class($responses, $sent) extends MockHandler
    {
        /** @param list<Response> $responses
         * @param  list<RequestInterface>  $sent
         */
        public function __construct(array $responses, private array &$sent)
        {
            parent::__construct($responses);
        }

        public function __invoke(RequestInterface $request, array $options): PromiseInterface
        {
            $this->sent[] = $request;

            return parent::__invoke($request, $options);
        }
    };
}

/** @return list<string> */
function crawlDispatchedUrls(Closure $operation): array
{
    $guard = app(PrivateTargetGuard::class);
    $audit = new class($guard) implements PrivateTargetGuard
    {
        /** @var list<array<string, mixed>> */
        public array $history = [];

        public function __construct(private readonly PrivateTargetGuard $guard) {}

        public function clientConfig(?string $siteHost, bool $publicTargetsOnly = false, ?float $deadline = null, ?float $selectTimeoutSeconds = null, ?TargetAddressScope $scope = null): array
        {
            $config = $this->guard->clientConfig($siteHost, $publicTargetsOnly, $deadline, $selectTimeoutSeconds, $scope);
            $config['handler']->push(Middleware::history($this->history));

            return $config;
        }
    };
    app()->instance(PrivateTargetGuard::class, $audit);
    try {
        $operation();

        return array_map(static fn (array $entry): string => (string) $entry['request']->getUri(), $audit->history);
    } finally {
        app()->instance(PrivateTargetGuard::class, $guard);
    }
}

function recordCrawl(CheckContext $context): void
{
    $check = Check::findOrFail($context->checkId);
    $outcome = app(CheckRegistry::class)->get($check->type)->execute($context);
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome))->accepted)->toBeTrue();
}

it('collects real HTTP findings and shares one accepted artifact with the derived check', function (): void {
    $check = null;
    $dispatched = crawlDispatchedUrls(function () use (&$check): void {
        $check = crawlCheck();
        $headers = [['name' => 'X-Crawler-Secret', 'value' => 'private-token']];
        $check->site->update(['crawler_headers' => $headers]);
        recordCrawl(crawlContext($check, $check->site->crawler_headers));
    });
    expect($dispatched)->toContain('https://fixtures.test/crawl-addon/page', 'https://fixtures.test/crawl-addon/broken-a')
        ->and(array_map(static fn (string $url): string => parse_url($url, PHP_URL_HOST), $dispatched))->not->toContain('external.fixtures.test');
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed);
    $rows = DB::table('crawl_findings')->get();
    expect($rows->where('crawled_url', 'https://fixtures.test/crawl-addon/broken-a')->first()->status_code)->toBe(404)
        ->and($rows->whereIn('crawled_url', ['http://external.fixtures.test/crawl-addon/header-check', 'https://fixtures.test/crawl-addon/external-redirect'])->map(static fn (object $row): array => [$row->type, $row->crawled_url, $row->status_code, $row->error_message])->values()->all())->toBe([
            ['link', 'https://fixtures.test/crawl-addon/external-redirect', null, 'private_target'],
            ['link', 'http://external.fixtures.test/crawl-addon/header-check', null, 'private_target'],
        ])

        ->and(json_encode($rows))->not->toContain('user:pass', '#fragment', 'private-token');
    $snapshot = DB::table('crawl_snapshots')->first();
    expect(json_encode(app(CrawlPersistence::class)->baseline($check->id)['artifact']->privateStorage(), JSON_THROW_ON_ERROR))->not->toContain('user:pass');
    expect(json_encode(app(CrawlPersistence::class)->baseline($check->id)['artifact']->privateStorage(), JSON_THROW_ON_ERROR))->not->toContain('#fragment');
    $mixed = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::MixedContent]);
    $this->travel(2)->seconds();
    recordCrawl(crawlContext($mixed, $mixed->site->crawler_headers));
    expect($mixed->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and(DB::table('crawl_collections')->count())->toBe(1)
        ->and(DB::table('crawl_snapshots')->where('check_id', $mixed->id)->value('collected_at'))->toBe($snapshot->collected_at)
        ->and(DB::table('mixed_content_findings')->where('snapshot_id', $snapshot->id)->value('element_name'))->toBe('img');
});

it('evaluates whitelist external-link policy robots and depth through the real crawler', function (array $config, RunResult $result, int $brokenInternal, int $brokenExternal, array $refusedUrls): void {
    $outcome = null;
    $dispatched = crawlDispatchedUrls(function () use ($config, &$outcome): void {
        $check = crawlCheck(url: 'http://fixtures.test/', config: $config);
        $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute(crawlContext($check));
    });
    expect($dispatched)->toContain('http://fixtures.test/')
        ->and(array_map(static fn (string $url): string => parse_url($url, PHP_URL_HOST), $dispatched))->not->toContain('external.fixtures.test');
    expect($outcome->result)->toBe($result)
        ->and($outcome->metrics->toArray()['broken_internal'])->toBe($brokenInternal)
        ->and($outcome->metrics->toArray()['broken_external'])->toBe($brokenExternal)
        ->and(DB::table('crawl_findings')->where('error_message', 'private_target')->orderBy('crawled_url')->get()->map(static fn (object $row): array => [$row->type, $row->crawled_url, $row->status_code, $row->error_message])->all())->toBe(array_map(static fn (string $url): array => ['link', $url, null, 'private_target'], $refusedUrls))

        ->and(DB::table('crawl_findings')->where('crawled_url', 'http://fixtures.test/blocked/')->exists())->toBeFalse();
})->with([
    'default' => [[], RunResult::Failed, 2, 0, ['http://external.fixtures.test/', 'http://external.fixtures.test/missing']],
    'whitelist internal' => [['whitelisted_urls' => ['http://fixtures.test/missing-internal', 'http://fixtures.test/missing-nested']], RunResult::Succeeded, 0, 0, ['http://external.fixtures.test/', 'http://external.fixtures.test/missing']],
    'internal only and whitelist' => [['check_include_external_links' => false, 'whitelisted_urls' => ['http://fixtures.test/missing-internal', 'http://fixtures.test/missing-nested']], RunResult::Succeeded, 0, 0, []],
    'depth zero' => [['max_depth' => 0], RunResult::Succeeded, 0, 0, []],
    'exclude broken' => [['do_not_crawl_urls' => ['*/missing*']], RunResult::Succeeded, 0, 0, ['http://external.fixtures.test/']],
]);

it('completes a reachable small site despite broken links and resolves recovered findings', function (): void {
    $root = 'https://fixtures.test/small-site';
    $broken = 'https://fixtures.test/small-site/missing';
    $check = crawlCheck(url: $root, config: ['respect_robots' => true, 'types' => ['link'], 'whitelisted_urls' => ['https://fixtures.test/ignored']]);
    $check->update(['failure_threshold' => 1]);
    foreach ([404, 200] as $status) {
        DB::table('crawl_collections')->delete();
        $fake = (new FakeOutboundHttp)
            ->respondWith(crawlHttpRequest('https://fixtures.test/robots.txt'), new FetchResult(404))
            ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$broken.'">Child</a>', ['Content-Type' => ['text/html']]))
            ->respondWith(crawlHttpRequest($broken), $status === 404 ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200, 'OK', ['Content-Type' => ['text/html']]));
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
        expect($artifact->discovery_complete)->toBeTrue()->and($artifact->truncated)->toBeFalse();
    }
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(DB::table('incidents')->where('check_id', $check->id)->whereNotNull('resolved_at')->count())->toBe(1);
});

it('counts HTML link targets as pages and checks assets within a separate status budget', function (): void {
    $root = 'https://fixtures.test/page-budget';
    $child = 'https://fixtures.test/page-budget/child';
    $asset = 'https://fixtures.test/page-budget/missing.png';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link', 'image'], 'max_pages' => 2]);
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$child.'">Child</a><img src="'.$asset.'">', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($child), new FetchResult(200, '<img src="'.$asset.'">', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($asset), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
    expect($artifact->discovery_complete)->toBeTrue()->and($artifact->truncated)->toBeFalse()
        ->and($artifact->observed_pages)->toContain($root, $child)
        ->and(DB::table('crawl_findings')->where('crawled_url', $asset)->value('status_code'))->toBe(404)
        ->and($fake->requests)->toHaveCount(3);
});

it('checks discovered resources in concurrent batches through the guarded HTTP seam', function (): void {
    $check = crawlCheck(url: 'http://fixtures.test/crawl-addon/parallel-status', config: ['respect_robots' => false, 'types' => ['image']]);
    recordCrawl(crawlContext($check));
    $peak = (int) (new Client)->get('http://fixtures.test/crawl-addon/parallel-status/peak')->getBody()->getContents();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and($peak)->toBe(5)
        ->and(DB::table('crawl_findings')->count())->toBe(11);
});

it('applies robots to status-only resources with a forced-URL exception', function (bool $forced): void {
    $root = 'https://fixtures.test/robots-resource';
    $blocked = 'https://fixtures.test/robots-resource/blocked.png';
    $allowed = 'https://fixtures.test/robots-resource/allowed.png';
    $check = crawlCheck(url: $root, config: ['respect_robots' => true, 'types' => ['image'], 'force_crawl_urls' => $forced ? [$blocked] : []]);
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest('https://fixtures.test/robots.txt'), new FetchResult(200, "User-agent: *\nDisallow: /robots-resource/blocked.png"))
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<img src="'.$blocked.'"><img src="'.$allowed.'">', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($allowed), new FetchResult(200));
    if ($forced) {
        $fake->respondWith(crawlHttpRequest($blocked), new FetchResult(200));
    }
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(in_array($blocked, DB::table('crawl_findings')->pluck('crawled_url')->all(), true))->toBe($forced)
        ->and($fake->requests)->toHaveCount($forced ? 4 : 3);
})->with([false, true]);

it('reports a pending page cap even when a 404 reduces observed HTML pages', function (): void {
    $root = 'https://fixtures.test/page-cap-root';
    $broken = 'https://fixtures.test/page-cap-broken';
    $pending = 'https://fixtures.test/page-cap-pending';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 2]);
    $check->update(['failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$broken.'">Broken</a><a href="'.$pending.'">Pending</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($broken), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check));
    $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and($check->summary)->toBe('broken_links.incomplete')
        ->and($artifact->truncated)->toBeTrue()
        ->and($artifact->page_cap_reached)->toBeTrue()
        ->and($artifact->observed_pages)->toHaveCount(1)
        ->and(CheckRun::query()->where('check_id', $check->id)->sole()->evidence['page_cap_reached'])->toBeTrue()
        ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1);
});

it('derives page-cap state from confirmed findings while reporting the limit separately', function (?string $broken, CheckResult $expected): void {
    $root = 'https://fixtures.test/cap-state';
    $first = 'https://fixtures.test/cap-state/first';
    $pending = 'https://fixtures.test/cap-state/pending';
    $external = 'https://external.fixtures.test/cap-state/missing';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 2]);
    $body = '<a href="'.$first.'">First</a><a href="'.$pending.'">Pending</a>';
    if ($broken === 'external') {
        $body .= '<a href="'.$external.'">External</a>';
    }
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, $body, ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($first), $broken === 'internal' ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200, 'OK', ['Content-Type' => ['text/html']]));
    if ($broken === 'external') {
        $fake->respondWith(crawlHttpRequest($external), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    }
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    $run = CheckRun::query()->where('check_id', $check->id)->sole();
    expect($check->refresh()->latest_run_result)->toBe($expected)
        ->and($check->summary)->toBe('broken_links.incomplete')
        ->and($run->result)->toBe($broken === 'internal' ? RunResult::Failed : ($broken === 'external' ? RunResult::Warning : RunResult::Succeeded))
        ->and($run->evidence['page_cap_reached'])->toBeTrue()
        ->and($run->evidence['observed_pages'])->toBe($broken === 'internal' ? 1 : 2)
        ->and($run->evidence['discovered_urls'])->toBe($broken === 'external' ? 4 : 3);
    if ($broken === null) {
        $user = User::factory()->create();
        $user->assignRole('Owner');
        config(['heptapod.features.ui_wiring' => true]);
        $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->where('detail.observed_pages', 2)->where('detail.discovered_urls', 3)
            ->where('detail.page_cap_reached', true)->where('detail.crawl_truncated', true));
    }
})->with([
    'no broken links' => [null, CheckResult::Pending],
    'external broken link' => ['external', CheckResult::Pending],
    'internal broken link' => ['internal', CheckResult::Failed],
]);

it('alerts on a confirmed broken link even when another page hits the crawl cap', function (): void {
    $root = 'https://fixtures.test/cap-alert';
    $stable = 'https://fixtures.test/stable';
    $broken = 'https://fixtures.test/new-broken';
    $pending = 'https://fixtures.test/pending';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 3]);
    $check->update(['failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$stable.'">Stable</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($stable), new FetchResult(200, 'OK', ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $check->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => 2])]);
    DB::table('crawl_collections')->delete();
    DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$broken.'">Broken</a><a href="'.$pending.'">Pending</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($broken), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and($check->summary)->toBe('broken_links.incomplete')
        ->and(Event::query()->where('type', 'broken_links.new_broken_found')->sole()->payload['count'])->toBe(1)
        ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1);
});

it('keeps an incident open when its broken link recovers during a capped crawl', function (): void {
    $root = 'https://fixtures.test/cap-incident';
    $broken = 'https://fixtures.test/cap-incident/broken';
    $pending = 'https://fixtures.test/cap-incident/pending';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 2]);
    $check->update(['failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$broken.'">Broken</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($broken), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check));
    $incident = DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->sole();
    DB::table('crawl_collections')->delete();
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$broken.'">Recovered</a><a href="'.$pending.'">Pending</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($broken), new FetchResult(200, 'OK', ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $run = CheckRun::query()->where('check_id', $check->id)->orderByDesc('id')->first();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and($run->result)->toBe(RunResult::Succeeded)
        ->and($check->summary)->toBe('broken_links.incomplete')
        ->and($run->evidence['page_cap_reached'])->toBeTrue()
        ->and($run->evidence['finding_ids'])->toBe([])
        ->and(DB::table('incidents')->where('id', $incident->id)->whereNull('resolved_at')->exists())->toBeTrue();
});

it('reaches a malformed link beyond the former 500-URL limit without exceeding the page work budget', function (): void {
    $check = crawlCheck(url: 'http://fixtures.test/crawl-addon/parity-large', config: ['respect_robots' => false, 'types' => ['link', 'image'], 'max_pages' => 200]);
    recordCrawl(crawlContext($check));
    $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
    expect($artifact->discovery_complete)->toBeTrue()
        ->and($artifact->truncated)->toBeFalse()
        ->and(count($artifact->observed_pages))->toBe(161)
        ->and(count($artifact->observed_urls))->toBeGreaterThan(500)
        ->toBeLessThanOrEqual(1200)
        ->and(DB::table('crawl_findings')->where('error_message', 'invalid URL')->pluck('crawled_url')->all())->toContain('http://SOME WORDS.invalid/page');
});

it('benchmarks 2000 pages plus 10000 internal and 10000 external status URLs', function (): void {
    $check = crawlCheck(url: 'http://fixtures.test/crawl-addon/parity-benchmark', config: ['respect_robots' => false, 'types' => ['link', 'image']]);
    $started = microtime(true);
    recordCrawl(crawlContext($check));
    $elapsed = microtime(true) - $started;
    $peak = memory_get_peak_usage(true);
    $run = CheckRun::query()->where('check_id', $check->id)->sole();
    fwrite(STDERR, sprintf("Crawl budget: %.2fs, %.1f MiB peak\n", $elapsed, $peak / 1048576));
    expect($run->summary)->toBe('broken_links.warning')
        ->and($run->evidence['comparison_limit_reached'])->toBeFalse()
        ->and(DB::table('crawl_snapshots')->where('run_id', $run->id)->exists())->toBeTrue()
        ->and($run->evidence['page_cap_reached'])->toBeFalse()
        ->and($run->evidence['resource_cap_reached'])->toBeFalse()
        ->and($run->evidence['time_limit_reached'])->toBeFalse()
        ->and($run->evidence['observed_pages'])->toBe(2000)
        ->and($run->evidence['observed_urls'])->toBe(22000)
        ->and($elapsed)->toBeLessThan(300)
        ->and($peak)->toBeLessThan(400 * 1048576);
})->group('benchmark');

it('marks resource-cap truncation separately from page and time limits for both crawl checks', function (): void {
    config(['heptapod.crawl.max_status_urls' => 1]);
    $root = 'https://fixtures.test/resource-cap';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['image']]);
    $check->update(['failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<img src="/asset-a"><img src="/asset-b">', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest('https://fixtures.test/asset-a'), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check));
    $mixed = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::MixedContent]);
    recordCrawl(crawlContext($mixed));
    foreach ([$check, $mixed] as $runCheck) {
        $evidence = CheckRun::query()->where('check_id', $runCheck->id)->sole()->evidence;
        expect($runCheck->refresh()->latest_run_result)->toBe($runCheck->type === CheckKey::BrokenLinks ? CheckResult::Failed : CheckResult::Pending)
            ->and($runCheck->summary)->toBe($runCheck->type->value.'.incomplete_resources')
            ->and($evidence['resource_cap_reached'])->toBeTrue()
            ->and($evidence['page_cap_reached'])->toBeFalse()
            ->and($evidence['time_limit_reached'])->toBeFalse()
            ->and(DB::table('incidents')->where('check_id', $runCheck->id)->whereNull('resolved_at')->count())->toBe($runCheck->type === CheckKey::BrokenLinks ? 1 : 0);
    }
});

it('accepts a scheme-glob whitelist through the API and excludes its confirmed broken link', function (): void {
    $site = Site::factory()->create(['url' => 'http://client-a.example/root']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->withToken($owner->createToken('crawl-config', ['*'])->plainTextToken);
    $this->putJson('/api/monitors/'.$site->id, ['broken_links_check_settings' => [
        'whitelisted_urls' => ['*://client-a.example/*'], 'respect_robots' => false, 'types' => ['link'],
    ]])->assertOk()->assertJsonPath('broken_links_check_settings.whitelisted_urls', ['*://client-a.example/*']);
    $broken = 'http://client-a.example/missing';
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($site->url, siteHost: 'client-a.example'), new FetchResult(200, '<a href="'.$broken.'">Missing</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($broken, siteHost: 'client-a.example'), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check->refresh()));
    expect(DB::table('crawl_findings')->where('crawled_url', $broken)->value('status_code'))->toBe(404)
        ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(CheckRun::query()->where('check_id', $check->id)->sole()->evidence['finding_ids'])->toBe([]);
});

it('records an ignored-invalid-whitelist note without failing a previously saved check', function (string $entry): void {
    $check = crawlCheck(url: 'https://fixtures.test/invalid-whitelist', config: ['respect_robots' => false, 'types' => ['link']]);
    DB::table('checks')->where('id', $check->id)->update(['config' => json_encode([...$check->config, 'whitelisted_urls' => [$entry]], JSON_THROW_ON_ERROR)]);
    $check->refresh();
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, 'OK', ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $run = CheckRun::query()->where('check_id', $check->id)->sole();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(array_column($run->evidence['notes'], 'key'))->toBe(['broken_links.whitelist_entry_ignored_invalid'])
        ->and(json_encode($run->evidence, JSON_THROW_ON_ERROR))->not->toContain('client-a.example:abc', $entry);
})->with([
    'invalid port' => 'https://client-a.example:abc/x',
    'bare bracket is not a redaction mask' => 'https://client-a.example/[',
]);

it('ignores Cloudflare email protection only at the exact same-origin path', function (): void {
    $root = 'https://fixtures.test/email-links';
    $nearby = 'https://fixtures.test/cdn-cgi/l/email-protection-extra';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="/cdn-cgi/l/email-protection?hash=abc#fragment">Protected</a><a href="'.$nearby.'">Nearby</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($nearby), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    expect($fake->requests)->toHaveCount(2)
        ->and(DB::table('crawl_findings')->pluck('crawled_url')->all())->not->toContain('https://fixtures.test/cdn-cgi/l/email-protection?hash=abc')
        ->toContain($nearby);
});

it('ignores the protected path across apex and www aliases without ignoring nearby paths', function (string $root, string $alias): void {
    $protected = $alias.'/cdn-cgi/l/email-protection?key=1';
    $nearby = $alias.'/cdn-cgi/l/email-protection-extra';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $fake = (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => $request->url === $root
        ? new FetchResult(200, '<a href="'.$protected.'#fragment">Protected</a><a href="'.$nearby.'">Nearby</a>', ['Content-Type' => ['text/html']])
        : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    expect($fake->requests)->toHaveCount(2)
        ->and(DB::table('crawl_findings')->pluck('crawled_url')->all())->not->toContain($protected)
        ->toContain($nearby);
})->with([
    ['https://example.com/contact/', 'https://www.example.com'],
    ['https://www.example.com/contact/', 'https://example.com'],
]);

it('suppresses a legacy email-protection finding retained by an incomplete crawl', function (string $legacyUrl): void {
    $root = 'https://example.com/legacy-protection';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['image']]);
    $html = '<img src="/legacy-a"><img src="/legacy-b">';
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root, siteHost: 'example.com'), new FetchResult(200, $html, ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest('https://example.com/legacy-a', siteHost: 'example.com'), new FetchResult(200))
        ->respondWith(crawlHttpRequest('https://example.com/legacy-b', siteHost: 'example.com'), new FetchResult(200));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    $legacy = (array) DB::table('crawl_findings')->where('crawled_url', $root)->sole();
    unset($legacy['id']);
    $legacy['crawled_url'] = $legacyUrl;
    $legacy['relative_crawled_url'] = '/cdn-cgi/l/email-protection?key=1';
    $legacy['status_code'] = 404;
    DB::table('crawl_findings')->insert($legacy);
    DB::table('crawl_collections')->delete();
    config(['heptapod.crawl.max_status_urls' => 1]);
    recordCrawl(crawlContext($check));
    $run = CheckRun::query()->where('check_id', $check->id)->latest('id')->first();
    expect(app(CrawlPersistence::class)->baseline($check->id)['artifact']->truncated)->toBeTrue()
        ->and($run->metrics['broken_internal'])->toBe(0)
        ->and($run->metrics['broken_external'])->toBe(0)
        ->and($run->evidence['finding_ids'])->toBe([])
        ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toBe([]);
})->with([
    'legacy query' => 'https://www.example.com/cdn-cgi/l/email-protection?key=1',
    'redacted userinfo and query' => 'https://[redacted]:[redacted]@example.com/cdn-cgi/l/email-protection?key=[redacted]',
]);

it('blocks a discovered private target without crashing and does not expose URL userinfo', function (): void {
    $outcome = null;
    $dispatched = crawlDispatchedUrls(function () use (&$outcome): void {
        $check = crawlCheck(url: 'http://fixtures.test/');
        $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute(crawlContext($check));
    });
    expect($dispatched)->toContain('http://fixtures.test/')
        ->and(array_map(static fn (string $url): string => parse_url($url, PHP_URL_HOST), $dispatched))->not->toContain('external.fixtures.test');
    expect($outcome->result)->toBe(RunResult::Failed)
        ->and(DB::table('crawl_findings')->where('internal', false)->orderBy('crawled_url')->get()->map(static fn (object $row): array => [$row->type, $row->crawled_url, $row->status_code, $row->error_message])->all())->toBe([
            ['link', 'http://external.fixtures.test/', null, 'private_target'],
            ['link', 'http://external.fixtures.test/missing', null, 'private_target'],
        ]);
});

it('crawls for mixed-content-only installations and rejects later HTTP site edits', function (): void {
    $check = crawlCheck(CheckKey::MixedContent, 'https://fixtures.test/mixed-content.html');
    $type = app(CheckRegistry::class)->get(CheckKey::MixedContent);
    $outcome = $type->execute(crawlContext($check));
    expect($outcome->result)->toBe(RunResult::Failed)->and($outcome->metrics->toArray()['mixed_content'])->toBe(3);
    $check->site->update(['url' => 'http://fixtures.test/']);
    $check->unsetRelation('site');
    app(ClaimService::class)->revoke($check);
    expect($type->execute(crawlContext($check))->summary)->toBe('mixed_content.not_https');
});

it('emits new findings once against retained accepted baselines at unchanged severity', function (CheckKey $key, string $eventType): void {
    $check = crawlCheck($key);
    recordCrawl(crawlContext($check));
    expect(Event::query()->where('type', $eventType)->count())->toBe(0);
    DB::table('crawl_collections')->delete();
    DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
    recordCrawl(crawlContext($check, [['name' => 'X-Crawl-Variant', 'value' => 'B']]));
    expect(Event::query()->where('type', $eventType)->count())->toBe(1)
        ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Failed);
    recordCrawl(crawlContext($check));
    expect(Event::query()->where('type', $eventType)->count())->toBe(1);
    $store = app(CrawlPersistence::class);
    $store->prune(CarbonImmutable::now()->addDays(61));
    expect(DB::table('crawl_snapshots')->where('check_id', $check->id)->count())->toBe(1);
    $event = Event::query()->where('type', $eventType)->first();
    expect(json_encode($event->payload))->not->toContain('private-token', 'http://', 'https://');
})->with([[CheckKey::BrokenLinks, 'broken_links.new_broken_found'], [CheckKey::MixedContent, 'mixed_content.new_finding']]);

it('fences staged findings and rolls back publication while retaining accepted baselines', function (): void {
    $check = crawlCheck(url: 'https://fixtures.test/mixed-content.html');
    $context = crawlContext($check);
    $store = app(CrawlPersistence::class);
    $type = app(CheckRegistry::class)->get(CheckKey::BrokenLinks);
    $type->execute($context);
    $run = new RunReference($context->runId, CarbonImmutable::parse('2026-10-02T12:00:00.123456Z'));
    $store->finalize($run, (string) Str::uuid());
    expect($store->baseline($check->id))->toBeNull();
    try {
        DB::transaction(function () use ($store, $run, $context): void {
            $store->finalize($run, $context->claimToken);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('rollback');
    }
    expect($store->baseline($check->id))->toBeNull();
    $store->finalize($run, $context->claimToken);
    $store->finalize($run, $context->claimToken);
    $store->discard($context->runId, $context->claimToken);
    expect($store->baseline($check->id)['run_id'])->toBe($context->runId)
        ->and(DB::table('crawl_snapshots')->value('run_ended_at'))->toContain('.123456');
    app(ClaimService::class)->revoke($check);
    $next = crawlContext($check);
    $type->execute($next);
    $store->discard($next->runId, $next->claimToken);
    $store->discard($next->runId, $next->claimToken);
    $store->finalize(new RunReference($next->runId, $next->startedAt), $next->claimToken);
    expect(DB::table('crawl_snapshots')->count())->toBe(1);
    expect(fn () => $store->stage($next, 'other', [[]]))->toThrow(InvalidArgumentException::class);
});

it('maps root HTTP seam failures to errored runs without caching a collection', function (FetchFailureKind $kind): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(microtime(true), 'UTC'));
    Queue::fake();
    $check = crawlCheck(config: ['respect_robots' => false, 'max_depth' => 0]);
    $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(failure: new FetchFailure($kind)));
    if ($kind === FetchFailureKind::TooLarge) {
        $fake->respondWith(crawlHeadRequest($check->site->url), new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout)));
    }
    app()->instance(OutboundHttp::class, $fake);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, 300, 'checks-crawl');
    app()->call($job->handle(...));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Errored)
        ->and(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1)
        ->and(DB::table('crawl_findings')->count())->toBe(0)
        ->and(DB::table('crawl_snapshots')->count())->toBe(0)
        ->and(DB::table('crawl_collections')->count())->toBe(0)
        ->and($fake->requests)->toHaveCount($kind === FetchFailureKind::TooLarge ? 2 : 1);
    if ($kind === FetchFailureKind::TooLarge) {
        $run = CheckRun::query()->forRun(new RunReference($check->latest_run_id, $check->latest_run_ended_at))->firstOrFail();
        expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'class' => RuntimeException::class,
            'message' => 'Crawl target could not be collected.',
        ]);
    }
})->with([FetchFailureKind::Timeout, FetchFailureKind::TooLarge, FetchFailureKind::Tls, FetchFailureKind::Dns, FetchFailureKind::Connect, FetchFailureKind::RedirectBlocked, FetchFailureKind::InvalidRequest]);

it('collects only once when two independent workers request the same site concurrently', function (): void {
    $siteId = random_int(100000000, 200000000);
    $client = new Client;
    $client->get('http://fixtures.test/crawl-addon/reset');
    $command = ['php', base_path('addons/crawl/tests/Fixtures/collect.php'), (string) $siteId];
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => DB::connection()->getDatabaseName()];
    $first = new Process($command, base_path(), $env);
    $second = new Process($command, base_path(), $env);
    try {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(trim($first->getOutput()))->toBe('0')
            ->and(trim($second->getOutput()))->toBe('0')
            ->and(trim((string) $client->get('http://fixtures.test/crawl-addon/count')->getBody()))->toBe('1');
    } finally {
        $first->stop();
        $second->stop();
        $cleanup = new Process([...$command, 'cleanup'], base_path(), $env);
        $cleanup->mustRun();
    }
});

it('re-evaluates whitelist changes without recollecting or new-finding events', function (): void {
    $check = crawlCheck(url: 'http://fixtures.test/', config: ['check_include_external_links' => false]);
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed);
    $schema = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema;
    $check->update(['config' => $schema->validateConfig(['check_include_external_links' => false, 'whitelisted_urls' => ['http://fixtures.test/missing-internal', 'http://fixtures.test/missing-nested']])]);
    $this->travel(2)->seconds();
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(DB::table('crawl_snapshots')->where('check_id', $check->id)->count())->toBe(2)
        ->and(DB::table('crawl_snapshots')->distinct()->pluck('collected_at')->all())->toHaveCount(1)
        ->and(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe(0);
});

it('reuses matching scheduled crawls but invalidates changed config and legacy snapshots', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'max_depth' => 0]);
    $root = $check->site->url;
    $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), new FetchResult(200, 'OK', ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    recordCrawl(crawlContext($check));
    expect($fake->requests)->toHaveCount(1);

    $schema = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema;
    $check->update(['config' => $schema->validateConfig(['respect_robots' => false, 'max_depth' => 0, 'max_pages' => 1000])]);
    recordCrawl(crawlContext($check));
    expect($fake->requests)->toHaveCount(2);

    DB::table('crawl_snapshots')->where('check_id', $check->id)->update(['config_fingerprint' => null]);
    $cached = json_decode((string) DB::table('crawl_collections')->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    unset($cached['config_fingerprint']);
    DB::table('crawl_collections')->where('site_id', $check->site_id)->update(['artifact' => json_encode($cached, JSON_THROW_ON_ERROR)]);
    recordCrawl(crawlContext($check));
    expect($fake->requests)->toHaveCount(3)
        ->and(DB::table('crawl_snapshots')->orderByDesc('id')->value('config_fingerprint'))->toHaveLength(64);
});

it('loads legacy stored reason strings without changing their evaluation', function (string $reason, NotCrawlableReason $typed): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'max_depth' => 0]);
    $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, 'OK', ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $fake);
    $context = crawlContext($check);
    $artifact = app(CrawlCollector::class)->ensureFresh($context)->privateStorage();
    $artifact['not_crawlable_reason'] = $reason;
    DB::table('crawl_collections')->where('site_id', $check->site_id)->update(['artifact' => json_encode($artifact, JSON_THROW_ON_ERROR)]);
    $stored = json_decode(DB::table('crawl_collections')->where('site_id', $check->site_id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);

    $loaded = CrawlArtifact::from($stored);
    $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute($context);

    expect($loaded->not_crawlable_reason)->toBe($typed);
    expect($loaded->privateStorage()['not_crawlable_reason'])->toBe($reason);
    // Legacy noindex collections are intentionally recollected under the current policy.
    expect($outcome->summary)->toBe($reason === 'noindex' ? 'broken_links.succeeded' : 'crawl.not_crawlable.'.$reason.'.not_checked')
        ->and($outcome->result)->toBe(RunResult::Succeeded)
        ->and($outcome->verifiedNothing)->toBe($reason !== 'noindex')
        ->and($outcome->evidence->toArray()['not_verifiable_count'])->toBe(in_array($reason, ['bot_protection', 'rate_limited'], true) ? 1 : 0);
    expect($fake->requests)->toHaveCount($reason === 'noindex' ? 2 : 1);
})->with([
    ['authentication_required', NotCrawlableReason::AuthenticationRequired],
    ['non_html', NotCrawlableReason::NonHtml],
    ['login_redirect', NotCrawlableReason::LoginRedirect],
    ['login_page', NotCrawlableReason::LoginPage],
    ['noindex', NotCrawlableReason::NoIndex],
    ['nofollow', NotCrawlableReason::Nofollow],
    ['start_url_excluded', NotCrawlableReason::StartUrlExcluded],
    ['robots_disallowed', NotCrawlableReason::RobotsDisallowed],
    ['bot_protection', NotCrawlableReason::BotProtection],
    ['rate_limited', NotCrawlableReason::RateLimited],
]);

it('rejects an unknown stored not-crawlable reason instead of treating it as crawlable', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false]);
    app()->instance(OutboundHttp::class, $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, 'OK', ['Content-Type' => ['text/html']])));
    $context = crawlContext($check);
    $artifact = app(CrawlCollector::class)->ensureFresh($context)->privateStorage();
    $artifact['not_crawlable_reason'] = 'unknown_reason';
    DB::table('crawl_collections')->where('site_id', $check->site_id)->update(['artifact' => json_encode($artifact, JSON_THROW_ON_ERROR)]);

    expect(fn () => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute($context))->toThrow(CannotCastEnum::class);
    expect($fake->requests)->toHaveCount(1);
    $this->assertDatabaseMissing('crawl_snapshots', ['check_id' => $check->id]);
});

it('reports robots before exclusion when both block the start URL', function (): void {
    $check = crawlCheck(config: ['respect_robots' => true, 'exclude_patterns' => ['*']]);
    app()->instance(OutboundHttp::class, $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest('https://fixtures.test/robots.txt'), new FetchResult(200, "User-agent: *\nDisallow: /\n")));

    $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute(crawlContext($check));

    expect($outcome->summary)->toBe('crawl.not_crawlable.robots_disallowed.not_checked')
        ->and($outcome->verifiedNothing)->toBeTrue();
    expect($fake->requests)->toHaveCount(1);
});

it('reports root failure before the deadline and forced exclusion', function (): void {
    $root = 'https://fixtures.test/root-failure';
    $check = crawlCheck(url: $root, config: ['respect_robots' => true, 'exclude_patterns' => ['*'], 'force_crawl_urls' => [$root]]);
    app()->instance(OutboundHttp::class, $fake = new FakeOutboundHttp()->respondUsing(static function (FetchRequest $request, CheckContext $context) use ($root): FetchResult {
        if ($request->url === 'https://fixtures.test/robots.txt') {
            return new FetchResult(200, "User-agent: *\nDisallow: /\n");
        }
        if ($request->url !== $root) {
            throw new LogicException('Unexpected crawl request.');
        }
        CarbonImmutable::setTestNow($context->deadline->addSecond());

        return new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout));
    }));

    $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute(crawlContext($check));

    expect($outcome->result)->toBe(RunResult::Errored)
        ->and($outcome->summary)->toBe('crawl.start_page_unreachable.timeout')
        ->and($outcome->error->class)->toBe(CrawlTargetUnavailable::class);
    expect($fake->requests)->toHaveCount(2);
    $this->assertDatabaseMissing('crawl_collections', ['site_id' => $check->site_id]);
});

it('runs a manual claim through a fresh crawl despite a matching cached snapshot', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(microtime(true), 'UTC'));
    Queue::fake();
    $check = crawlCheck(config: ['respect_robots' => false, 'max_depth' => 0]);
    $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, 'OK', ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, 300, 'checks-crawl');
    app()->call($job->handle(...));
    expect($fake->requests)->toHaveCount(2)
        ->and($check->refresh()->manual_run)->toBeFalse();
});

it('shares one crawl across both checks and refreshes once for a crawl change or either manual run', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(microtime(true), 'UTC'));
    Queue::fake();
    $check = crawlCheck(config: ['respect_robots' => false, 'max_depth' => 0, 'types' => ['link']]);
    $mixed = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::MixedContent, 'enabled' => true]);
    $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, '<img src="http://fixtures.test/insecure.png">', ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $fake);

    recordCrawl(crawlContext($mixed));
    recordCrawl(crawlContext($check));
    expect($fake->requests)->toHaveCount(1)
        ->and($mixed->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and(DB::table('mixed_content_findings')->where('snapshot_id', DB::table('crawl_snapshots')->where('check_id', $mixed->id)->value('id'))->count())->toBe(1);

    $schema = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema;
    $check->update(['config' => $schema->validateConfig(['respect_robots' => false, 'max_depth' => 0, 'max_pages' => 1000, 'types' => ['link']])]);
    recordCrawl(crawlContext($check));
    recordCrawl(crawlContext($mixed));
    expect($fake->requests)->toHaveCount(2);

    foreach ([$mixed, $check] as $manual) {
        expect(app(ClaimService::class)->runNow($manual->id, RunOrigin::System))->not->toBeNull();
        $job = new RunCheck($manual->id, $manual->refresh()->claim_token, 300, 'checks-crawl');
        app()->call($job->handle(...));
        recordCrawl(crawlContext($manual->id === $mixed->id ? $check : $mixed));
        expect($fake->requests)->toHaveCount($manual->id === $mixed->id ? 3 : 4);
    }
});

it('refuses a private off-site start redirect before dispatching the destination', function (): void {
    $outcome = null;
    $dispatched = crawlDispatchedUrls(function () use (&$outcome): void {
        $check = crawlCheck(url: 'http://fixtures.test/crawl-addon/private-redirect');
        $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute(crawlContext($check));
    });
    expect($outcome->result)->toBe(RunResult::Errored)
        ->and($outcome->summary)->toBe('crawl.start_redirect_off_site')
        ->and($outcome->summaryParams)->toBe(['start_redirect_target_url' => 'http://127.0.0.1/crawl-addon/private'])
        ->and($outcome->error?->message)->toBe('Crawl start URL redirects off-site.')
        ->and($dispatched)->toBe(['http://fixtures.test/robots.txt', 'http://fixtures.test/crawl-addon/private-redirect'])
        ->and(DB::table('crawl_findings')->count())->toBe(0);
});

it('reports an unavailable root status even at the configured page limit', function (): void {
    $check = crawlCheck(url: 'http://fixtures.test/status/404', config: ['max_pages' => 1]);
    $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute(crawlContext($check));
    expect($outcome->result)->toBe(RunResult::Errored)
        ->and($outcome->summary)->toBe('crawl.start_page_unreachable.http_status')
        ->and($outcome->summaryParams)->toBe(['status' => 404])
        ->and($outcome->error?->message)->toBe('Crawl start page unavailable. Outbound HTTP failed: http_status (HTTP 404).')
        ->and(DB::table('crawl_findings')->count())->toBe(0);
});

it('preserves the typed timeout cause without caching a collection', function (): void {
    config(['heptapod.crawl.request_timeout_seconds' => 1]);
    $check = crawlCheck(url: 'http://fixtures.test/timeout', config: ['respect_robots' => false]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout))));
    $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute(crawlContext($check));
    expect($outcome->result)->toBe(RunResult::Errored)
        ->and($outcome->summary)->toBe('crawl.start_page_unreachable.timeout')
        ->and($outcome->error?->class)->toBe(CrawlTargetUnavailable::class)
        ->and($outcome->error?->message)->toBe('Crawl start page unavailable. Outbound HTTP failed: timeout.');
    expect(DB::table('crawl_collections')->count())->toBe(0)
        ->and(DB::table('crawl_snapshots')->count())->toBe(0);
});

it('reports actionable not-checked summaries when exclusion settings exclude the start URL', function (array $settings, CheckKey $key, bool $hasPrevious): void {
    $broken = crawlCheck(config: ['respect_robots' => false, ...$settings]);
    $check = $key === CheckKey::BrokenLinks ? $broken : Check::factory()->create(['site_id' => $broken->site_id, 'type' => $key, 'enabled' => true]);
    $http = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $http);
    $claimed = crawlContext($check);
    $previous = $hasPrevious ? new PreviousResult(new RunReference(1, CarbonImmutable::parse('2026-10-27T12:00:00Z')), RunResult::Failed, null, 'previous', [], null) : null;
    $context = new CheckContext($claimed->siteId, $claimed->checkId, $claimed->runId, $claimed->claimToken, $claimed->url,
        $claimed->startedAt, $claimed->deadline, $claimed->config, $claimed->crawlerHeaders, $previous);

    $outcome = app(CheckRegistry::class)->get($key)->execute($context);

    expect($outcome)->toBeInstanceOf(CheckOutcome::class)
        ->result->toBe(RunResult::Succeeded)
        ->incomplete->toBeTrue()
        ->verifiedNothing->toBeTrue()
        ->error->toBeNull()
        ->summary->toBe($hasPrevious ? 'crawl.not_crawlable.start_url_excluded' : 'crawl.not_crawlable.start_url_excluded.not_checked');
    expect(__($outcome->summary, [], 'en'))->toBe($hasPrevious
        ? "Not crawlable: your exclusion settings exclude the start page. Remove it from 'Do not crawl URLs' or 'Exclude patterns'."
        : "Not checked: your exclusion settings exclude the start page. Remove it from 'Do not crawl URLs' or 'Exclude patterns'.")
        ->and(__($outcome->summary, [], 'de'))->toBe($hasPrevious
            ? 'Nicht crawlbar: Ihre Ausschlusseinstellungen schließen die Startseite aus. Entfernen Sie sie aus „Nicht crawlen“ oder „Ausschlussmuster“.'
            : 'Nicht geprüft: Ihre Ausschlusseinstellungen schließen die Startseite aus. Entfernen Sie sie aus „Nicht crawlen“ oder „Ausschlussmuster“.');
    expect($http->requests)->toBeEmpty();
    $this->assertDatabaseHas('crawl_collections', ['site_id' => $check->site_id]);
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome))->accepted)->toBeTrue();
    $this->assertDatabaseMissing('crawl_snapshots', ['site_id' => $check->site_id]);
})->with([
    'explicit start URL' => [['do_not_crawl_urls' => ['https://fixtures.test/crawl-addon/page']]],
    'catch-all exclude pattern' => [['exclude_patterns' => ['*']]],
])->with([CheckKey::BrokenLinks, CheckKey::MixedContent])->with(['first check' => false, 'previous result' => true]);

it('checks an oversized external resource by HEAD without aborting collection', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false]);
    $external = 'http://external.fixtures.test/large';
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, '<a href="'.$external.'">External</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($external), new FetchResult(200, failure: new FetchFailure(FetchFailureKind::TooLarge, 200)))
        ->respondWith(crawlHeadRequest($external), new FetchResult(405, failure: new FetchFailure(FetchFailureKind::HttpStatus, 405)));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(DB::table('crawl_findings')->where('crawled_url', $external)->value('status_code'))->toBe(200)
        ->and($fake->requests)->toHaveCount(3);
});

it('overlaps slow pages at the configured crawl concurrency', function (): void {
    $token = (string) Str::uuid();
    $check = crawlCheck(url: 'http://fixtures.test/crawl-addon/benchmark?token='.$token, config: ['respect_robots' => false, 'max_depth' => 1]);
    recordCrawl(crawlContext($check));
    $peak = trim((string) (new Client)->get('http://fixtures.test/crawl-addon/benchmark/peak?token='.$token)->getBody());

    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and((int) $peak)->toBe(5);
});

it('isolates oversized pages and malformed URLs in real-shaped fixture collection', function (): void {
    $check = crawlCheck(url: 'https://fixtures.test/crawl-addon/real-shaped', config: ['respect_robots' => false, 'check_include_external_links' => false]);
    recordCrawl(crawlContext($check, [['name' => 'X-Secret', 'value' => 'private-token']]));
    $findings = DB::table('crawl_findings')->get();
    $missing = $findings->where('crawled_url', 'https://fixtures.test/status/404')->first();
    $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
    $diagnostic = json_encode([
        'result' => $check->refresh()->latest_run_result?->value,
        'missing_status' => $missing?->status_code,
        'missing_error' => $missing?->error_message,
        'findings' => $findings->count(),
        'request_failures' => $findings->where('error_message', 'request_failed')->count(),
        'observed_pages' => count($artifact->observed_pages),
        'discovered_urls' => count($artifact->discovered_urls),
        'complete' => $artifact->discovery_complete,
        'truncated' => $artifact->truncated,
    ], JSON_THROW_ON_ERROR);
    expect($missing?->status_code)->toBe(404, $diagnostic)
        ->and($check->latest_run_result)->toBe(CheckResult::Failed, $diagnostic)
        ->and($findings->where('crawled_url', 'https://fixtures.test/crawl-addon/oversized-page')->first()->status_code)->toBe(200)
        ->and($findings->where('crawled_url', 'https://fixtures.test/crawl-addon/oversized-page')->first()->error_message)->toBe('page too large (links not extracted)')
        ->and($findings->where('crawled_url', 'https://fixtures.test/crawl-addon/oversized-asset')->first()->status_code)->toBe(200)
        ->and($findings->where('crawled_url', 'https://fixtures.test/crawl-addon/valid-child')->first()->status_code)->toBe(200)
        ->and($findings->where('crawled_url', 'https://fixtures.test/crawl-addon/after-cap')->count())->toBe(0)
        ->and($findings->where('crawled_url', 'https://xn--bcher-kva.example/')->count())->toBe(0)
        ->and($findings->where('error_message', 'invalid URL')->where('status_code', null)->pluck('crawled_url')->all())->toContain('http://LOUD NOISE: Notes from a Demo…')
        ->and($findings->where('error_message', 'invalid URL')->where('status_code', null)->count())->toBe(8)
        ->and($findings->where('error_message', 'invalid URL')->where('status_code', null)->first()->found_on_url)->toBe($check->site->url)
        ->and(json_encode($findings))->not->toContain('javascript:', 'mailto:', 'tel:', 'data:', 'user:pass', 'private-token');
    $mixed = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::MixedContent]);
    recordCrawl(crawlContext($mixed));
    expect($mixed->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and(DB::table('mixed_content_findings')->where('snapshot_id', DB::table('crawl_snapshots')->where('check_id', $mixed->id)->value('id'))->orderBy('mixed_content_url')->pluck('mixed_content_url')->all())->toBe(['http://fixtures.test/crawl-addon/image', 'http://xn--bcher-kva.example/image']);
});

it('status-checks browser-encoded spaces, matches the whitelist and rejects malformed hosts', function (string $whitelist): void {
    $url = 'http://fixtures.test/section/Some%20Title%20_%201996/';
    $check = crawlCheck(url: 'https://fixtures.test/crawl-addon/browser-urls', config: ['respect_robots' => false, 'types' => ['link'], 'whitelisted_urls' => [$whitelist]]);
    recordCrawl(crawlContext($check));
    expect(DB::table('crawl_findings')->where('crawled_url', $url)->value('status_code'))->toBe(200)
        ->and(DB::table('crawl_findings')->where('crawled_url', $url)->value('error_message'))->toBeNull()
        ->and(DB::table('crawl_findings')->where('crawled_url', 'http://fixtures.test/section/Missing%20Title/')->value('status_code'))->toBe(404)
        ->and(CheckRun::query()->where('check_id', $check->id)->sole()->evidence['finding_ids'])->toHaveCount(1)
        ->and(DB::table('crawl_findings')->where('error_message', 'invalid URL')->pluck('crawled_url')->all())->toContain('http://SOME WORDS: Invalid');
})->with([
    'exact URL' => 'http://fixtures.test/section/Missing Title/',
    'wildcard scheme and path' => '*://fixtures.test/section/Missing Title*',
]);

it('keeps malformed multibyte labels valid at the byte cap', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 1]);
    $prefix = 'http://exa mple/';
    $malformed = $prefix.str_repeat('a', 399 - strlen($prefix)).'ü'.str_repeat('b', 10);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, '<a href="'.$malformed.'">Invalid</a>', ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $finding = DB::table('crawl_findings')->where('error_message', 'invalid URL')->sole();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Warning)
        ->and($finding->status_code)->toBeNull()
        ->and(str_starts_with($finding->crawled_url, $prefix))->toBeTrue()
        ->and(mb_check_encoding($finding->crawled_url, 'UTF-8'))->toBeTrue();
});

it('removes credentials and fragments from malformed browser-style HTTP references', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 1]);
    $body = '<a href="http:user:pw@exa mple#fragment">Colon</a><a href="http:\\user:pw@ano ther#fragment">Slash</a>';
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, $body, ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $labels = DB::table('crawl_findings')->where('error_message', 'invalid URL')->pluck('crawled_url')->all();
    expect($labels)->toHaveCount(2);
    foreach ($labels as $label) {
        expect(str_contains($label, 'user:pw'))->toBeFalse()
            ->and(str_contains($label, 'fragment'))->toBeFalse();
    }
    $stored = json_encode([DB::table('crawl_findings')->get(), DB::table('crawl_collections')->get()], JSON_THROW_ON_ERROR);
    expect(str_contains($stored, 'user:pw'))->toBeFalse()
        ->and(str_contains($stored, 'fragment'))->toBeFalse();
});

it('bounds duplicate and distinct malformed links on one page', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'types' => ['link']]);
    $body = str_repeat('<a href="http://exa mple/repeated">Repeated</a>', 150);
    foreach (range(1, 150) as $index) {
        $body .= '<a href="http://exa mple/'.$index.'">Distinct</a>';
    }
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, $body, ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
    expect(DB::table('crawl_findings')->where('error_message', 'invalid URL')->count())->toBe(100)
        ->and($artifact->truncated)->toBeTrue()
        ->and($artifact->discovery_complete)->toBeFalse();
});

it('retains descendant broken links when their parent HTML grows past the body cap', function (): void {
    $root = 'https://fixtures.test/crawl-addon/size-carry-root';
    $page = 'https://fixtures.test/crawl-addon/size-carry-page';
    $broken = 'https://fixtures.test/crawl-addon/size-carry-broken';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 3]);
    $check->update(['failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$page.'">Page</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($page), new FetchResult(200, '<a href="'.$broken.'">Broken</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($broken), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check));
    $before = app(CrawlPersistence::class)->baseline($check->id)['finding_ids'];
    DB::table('crawl_collections')->delete();
    DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$page.'">Page</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($page), new FetchResult(failure: new FetchFailure(FetchFailureKind::TooLarge)))
        ->respondWith(crawlHeadRequest($page), new FetchResult(200, headers: ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toBe($before)
        ->and(DB::table('crawl_findings')->where('crawled_url', $broken)->orderByDesc('id')->value('status_code'))->toBe(404)
        ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1)
        ->and(Event::query()->where('type', 'incident.resolved')->count())->toBe(0);
});

it('retains mixed-content findings when a previously parsed page becomes oversized', function (): void {
    $root = 'https://fixtures.test/crawl-addon/mixed-size-root';
    $child = 'https://fixtures.test/crawl-addon/mixed-size-child';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = Check::factory()->create(['site_id' => $settings->site_id, 'type' => CheckKey::MixedContent, 'failure_threshold' => 1]);
    $first = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$child.'">Child</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($child), new FetchResult(200, '<img src="http://fixtures.test/mixed-image">', ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $first);
    recordCrawl(crawlContext($check));
    $baseline = app(CrawlPersistence::class)->baseline($check->id)['finding_ids'];
    DB::table('crawl_collections')->delete();
    DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
    $next = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$child.'">Child</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($child), new FetchResult(failure: new FetchFailure(FetchFailureKind::TooLarge)))
        ->respondWith(crawlHeadRequest($child), new FetchResult(200, headers: ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $next);
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toBe($baseline)
        ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1)
        ->and(DB::table('crawl_findings')->where('crawled_url', $child)->orderByDesc('id')->value('error_message'))->toBe('page too large (links not extracted)');
});

it('keeps child transport and redirect failures as per-URL findings', function (FetchFailureKind $kind): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'types' => ['link']]);
    $child = 'https://fixtures.test/crawl-addon/failed-child';
    $healthy = 'https://fixtures.test/crawl-addon/healthy-child';
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, '<a href="'.$child.'">Failed</a><a href="'.$healthy.'">Healthy</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($child), new FetchResult(failure: new FetchFailure($kind)))
        ->respondWith(crawlHttpRequest($healthy), new FetchResult(200));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(in_array($kind, [FetchFailureKind::Timeout, FetchFailureKind::Connect], true) ? CheckResult::Warning : CheckResult::Failed)
        ->and(DB::table('crawl_findings')->where('crawled_url', $child)->value('status_code'))->toBeNull()
        ->and(DB::table('crawl_findings')->where('crawled_url', $child)->value('error_message'))->toBe(match ($kind) {
            FetchFailureKind::Timeout => 'unchecked (timeout)',
            FetchFailureKind::Connect => 'unchecked (connect)',
            FetchFailureKind::Tls => 'tls_handshake_failed',
            FetchFailureKind::TooManyRedirects => 'too_many_redirects',
            default => 'request_failed',
        })
        ->and(DB::table('crawl_findings')->where('crawled_url', $healthy)->value('status_code'))->toBe(200);
})->with([FetchFailureKind::Timeout, FetchFailureKind::Tls, FetchFailureKind::TooManyRedirects, FetchFailureKind::InvalidRequest, FetchFailureKind::Connect]);

it('marks a timed-out or disconnected child unchecked without resolving its prior broken status', function (FetchFailureKind $kind): void {
    $root = 'https://fixtures.test/crawl-addon/page';
    $child = 'https://fixtures.test/status/404';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check->update(['failure_threshold' => 1]);
    $rootResponse = new FetchResult(200, '<a href="'.$child.'">Missing</a>', ['Content-Type' => ['text/html']]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), $rootResponse)
        ->respondWith(crawlHttpRequest($child), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check));
    $previous = app(CrawlPersistence::class)->baseline($check->id)['finding_ids'];
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and($previous)->toHaveCount(1);

    DB::table('crawl_collections')->delete();
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), $rootResponse)
        ->respondWith(crawlHttpRequest($child), new FetchResult(failure: new FetchFailure($kind))));
    recordCrawl(crawlContext($check));
    $snapshot = DB::table('crawl_snapshots')->where('check_id', $check->id)->orderByDesc('run_id')->first();
    $rows = DB::table('crawl_findings')->where('snapshot_id', $snapshot->id)->where('crawled_url', $child)->get();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and($rows->where('status_code', 404)->count())->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->current_error_message)->toBe($kind === FetchFailureKind::Timeout ? 'unchecked (timeout)' : 'unchecked (connect)')
        ->and(CheckRun::query()->where('check_id', $check->id)->orderByDesc('id')->first()->evidence['unchecked_count'])->toBe(0)
        ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toBe($previous)
        ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1)
        ->and(Event::query()->whereIn('type', ['incident.resolved', 'broken_links.new_broken_found'])->count())->toBe(0);

    DB::table('crawl_collections')->delete();
    $other = crawlCheck(url: 'https://fixtures.test/crawl-addon/other', config: ['respect_robots' => false, 'types' => ['link']]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($other->site->url), $rootResponse)
        ->respondWith(crawlHttpRequest($child), new FetchResult(failure: new FetchFailure($kind))));
    recordCrawl(crawlContext($other));
    expect($other->refresh()->latest_run_result)->toBe(CheckResult::Warning)
        ->and(app(CrawlPersistence::class)->baseline($other->id)['finding_ids'])->toBe([])
        ->and(CheckRun::query()->where('check_id', $other->id)->orderByDesc('id')->first()->evidence['unchecked_count'])->toBe(1)
        ->and(DB::table('crawl_findings')->where('snapshot_id', DB::table('crawl_snapshots')->where('check_id', $other->id)->value('id'))->where('crawled_url', $child)->value('error_message'))->toBe($kind === FetchFailureKind::Timeout ? 'unchecked (timeout)' : 'unchecked (connect)');
})->with([FetchFailureKind::Timeout, FetchFailureKind::Connect]);

it('accepts an oversized root status without parsing beyond the cap', function (): void {
    $check = crawlCheck(url: 'https://fixtures.test/crawl-addon/oversized-page', config: ['respect_robots' => false]);
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Pending)
        ->and(CheckRun::query()->where('check_id', $check->id)->sole()->result)->toBe(RunResult::Succeeded)
        ->and($check->summary)->toBe('broken_links.incomplete_other')
        ->and(DB::table('crawl_findings')->value('status_code'))->toBe(200)
        ->and(DB::table('crawl_findings')->value('error_message'))->toBe('page too large (links not extracted)')
        ->and(DB::table('crawl_findings')->where('crawled_url', 'https://fixtures.test/crawl-addon/before-cap')->exists())->toBeFalse();
});

it('redacts reflected URL and header secrets before persistence whitelist matching and finding identity', function (): void {
    $secret = 'crawler-secret';
    $querySecret = 'query-secret';
    $check = crawlCheck(url: 'https://fixtures.test/crawl-addon/reflected?token='.$querySecret, config: ['respect_robots' => false, 'types' => ['link'], 'whitelisted_urls' => ['https://fixtures.test/crawl-addon/reflected-whitelisted/'.$secret.'?credential='.$querySecret]]);
    $baseline = null;
    foreach ([['lower', false], ['upper', false], ['upper', true]] as [$case, $new]) {
        $headers = [
            ['name' => 'X-Site-Secret', 'value' => $secret],
            ['name' => 'X-Mask-Substring', 'value' => 'dact'],
            ['name' => 'X-Overlapping-Secret', 'value' => 'crawler'],
            ['name' => 'X-Crawl-Case', 'value' => $case],
            ['name' => 'X-Crawl-New', 'value' => $new ? 'true' : 'false'],
        ];
        $check->site->update(['crawler_headers' => $headers]);
        recordCrawl(crawlContext($check, $headers));
        $snapshot = DB::table('crawl_snapshots')->where('check_id', $check->id)->orderByDesc('run_id')->first();
        $identity = app(CrawlPersistence::class)->baseline($check->id)['finding_ids'];
        $persisted = json_encode([
            DB::table('crawl_findings')->get(), DB::table('mixed_content_findings')->get(),
            DB::table('crawl_snapshots')->get(), DB::table('crawl_collections')->get(),
            CheckRun::query()->get(), Event::query()->get(),
        ], JSON_THROW_ON_ERROR);
        expect(strtolower($persisted))->not->toContain($secret)
            ->and(strtolower($persisted))->not->toContain($querySecret)
            ->and($persisted)->toContain('[redacted]', 'public-marker')
            ->and($identity)->toHaveCount(1)
            ->and(DB::table('crawl_findings')->where('snapshot_id', $snapshot->id)->where('crawled_url', 'https://fixtures.test/crawl-addon/reflected-broken/[redacted]'.($new ? '-new' : '').'?credential=[redacted]')->value('link_text'))->toBe('[redacted]|[redacted]|[redacted]|[redacted]|[redacted]|public-marker');
        if ($baseline !== null) {
            if ($new) {
                expect($identity)->not->toBe($baseline);
            } else {
                expect($identity)->toBe($baseline);
            }
        }
        expect(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe($new ? 1 : 0);
        $baseline = $identity;
        $collectedAt = DB::table('crawl_collections')->value('collected_at');
        recordCrawl(crawlContext($check, $headers));
        expect(DB::table('crawl_collections')->value('collected_at'))->toBe($collectedAt)
            ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toBe($identity)
            ->and(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe($new ? 1 : 0);
        DB::table('crawl_collections')->delete();
    }
});

it('records bodyless HTTP status failures without exposing crawler headers in findings diagnostics or logs', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(microtime(true), 'UTC'));
    Queue::fake();
    $check = crawlCheck(config: ['respect_robots' => false]);
    $check->site->update(['crawler_headers' => [['name' => 'X-Site-Secret', 'value' => 'secret-header']]]);
    $child = 'https://fixtures.test/bodyless-failed';
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($check->site->url, ['X-Site-Secret' => ['secret-header']]), new FetchResult(200, '<a href="'.$child.'">Broken</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($child, ['X-Site-Secret' => ['secret-header']]), new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500)));
    app()->instance(OutboundHttp::class, $fake);
    $logs = [];
    Illuminate\Support\Facades\Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = [$event->message, $event->context];
    });
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, 300, 'checks-crawl');
    app()->call($job->handle(...));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and(json_encode([DB::table('crawl_findings')->get(), CheckRun::query()->first()->error, $logs]))->not->toContain('secret-header')
        ->and($fake->requests[0]['request']->headers['X-Site-Secret'])->toBe(['secret-header'])
        ->and(CheckRun::query()->first()->error)->toBeNull()
        ->and(DB::table('crawl_findings')->where('crawled_url', $child)->value('status_code'))->toBe(500);
});

it('distinguishes secret query target edits without exposing its keyed cache identity', function (): void {
    $headerSecret = 'crawler-header-secret';
    $headers = [['name' => 'X-Site-Secret', 'value' => $headerSecret]];
    $firstUrl = 'https://fixtures.test/crawl-addon/page?token=first-secret';
    $secondUrl = 'https://fixtures.test/crawl-addon/page?token=second-secret';
    $check = crawlCheck(url: $firstUrl, config: ['respect_robots' => false, 'max_depth' => 0]);
    $check->site->update(['crawler_headers' => $headers]);
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($firstUrl, ['X-Site-Secret' => [$headerSecret]]), new FetchResult(200, 'First public marker', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($secondUrl, ['X-Site-Secret' => [$headerSecret]]), new FetchResult(200, 'Second public marker', ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check, $headers));
    recordCrawl(crawlContext($check, $headers));
    expect($fake->requests)->toHaveCount(1);
    Bus::fake([RefreshSiteIcon::class]);
    $check->site->update(['url' => $secondUrl]);
    recordCrawl(crawlContext($check, $headers));
    expect($fake->requests)->toHaveCount(2);
    $fingerprints = DB::table('crawl_snapshots')->distinct()->pluck('target_fingerprint')->all();
    expect($fingerprints)->toHaveCount(2);
    $artifact = app(CrawlPersistence::class)->latestAccepted($check->site_id);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $token = $owner->createToken('crawl-identity', ['*'])->plainTextToken;
    $response = $this->withToken($token)->getJson('/api/monitors/'.$check->site_id)->assertOk();
    $public = json_encode([$response->json(), $artifact->toArray(), Event::query()->get()], JSON_THROW_ON_ERROR);
    expect($public)->not->toContain($headerSecret);
    foreach ($fingerprints as $fingerprint) {
        expect($public)->not->toContain($fingerprint);
    }
    $stored = json_encode([DB::table('crawl_snapshots')->get(), DB::table('crawl_collections')->get()], JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain('first-secret');
    expect($stored)->not->toContain('second-secret');
});

it('projects OpenGraph resources with the canonical API resource type', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'types' => ['og:image']]);
    $fake = (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, '<meta property="og:image" content="/crawl-addon/meta-missing">', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest('https://fixtures.test/crawl-addon/meta-missing'), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    app()->instance(OutboundHttp::class, $fake);
    recordCrawl(crawlContext($check));
    expect(DB::table('crawl_findings')->where('status_code', 404)->value('type'))->toBe('og:image')
        ->and($fake->requests)->toHaveCount(2);
});

it('carries unobserved findings across page caps without recovery or rediscovery', function (CheckKey $key): void {
    $root = 'https://fixtures.test/limited';
    $page = 'https://fixtures.test/unobserved-a';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = $key === CheckKey::BrokenLinks ? $settings : Check::factory()->create(['site_id' => $settings->site_id, 'type' => $key]);
    $check->update(['failure_threshold' => 1]);
    $ids = null;
    foreach ([500, 1, 500] as $limit) {
        $settings->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => $limit])]);
        DB::table('crawl_collections')->delete();
        DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$page.'">A</a>', ['Content-Type' => ['text/html']]));
        if ($limit > 1) {
            $fake->respondWith(crawlHttpRequest($page), $key === CheckKey::BrokenLinks
                ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))
                : new FetchResult(200, '<img src="http://fixtures.test/image">', ['Content-Type' => ['text/html']]));
        }
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
            ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1);
        if ($limit === 1) {
            $user = User::factory()->create();
            $user->assignRole('Owner');
            config(['heptapod.features.ui_wiring' => true]);
            expect($check->refresh()->summary)->toBe($key->value.'.incomplete');
            $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
                ->where('detail.crawl_truncated', true)->where('detail.discovery_complete', false)->where('detail.page_cap', 1)->where('detail.observed_pages', 1));
        }
        $current = app(CrawlPersistence::class)->baseline($check->id)['finding_ids'];
        expect($current)->toHaveCount(1);
        if ($ids !== null) {
            expect($current)->toBe($ids);
        }
        $ids = $current;
        expect(Event::query()->where('type', $key === CheckKey::BrokenLinks ? 'broken_links.new_broken_found' : 'mixed_content.new_finding')->count())->toBe(0);
    }
})->with([CheckKey::BrokenLinks, CheckKey::MixedContent]);

it('updates observed URLs while retaining a failure Spatie drops after the page cap', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'types' => ['link']]);
    $root = $check->site->url;
    $a = 'https://fixtures.test/observed-a';
    $b = 'https://fixtures.test/dropped-b';
    foreach ([false, true, false] as $index => $limited) {
        $check->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => $limited ? 2 : 500])]);
        DB::table('crawl_collections')->delete();
        $fake = (new FakeOutboundHttp)
            ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$a.'">A</a><a href="'.$b.'">B</a>', ['Content-Type' => ['text/html']]))
            ->respondWith(crawlHttpRequest($a), $index === 0 ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200))
            ->respondWith(crawlHttpRequest($b), $limited ? new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout)) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
            ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toHaveCount($index === 0 ? 2 : 1)
            ->and(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe(0);
        if ($limited) {
            $snapshot = DB::table('crawl_snapshots')->orderByDesc('run_id')->first();
            expect(json_decode($snapshot->observed_urls, true, flags: JSON_THROW_ON_ERROR))->not->toContain($b);
            expect(DB::table('crawl_findings')->where('snapshot_id', $snapshot->id)->where('crawled_url', $b)->value('status_code'))->toBe(404);
        }
    }
});

it('retains mixed findings and incidents when required HTML becomes unavailable', function (string $failure): void {
    $root = 'https://fixtures.test/mixed-observation';
    $child = 'https://fixtures.test/mixed-child';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = Check::factory()->create(['site_id' => $settings->site_id, 'type' => CheckKey::MixedContent, 'failure_threshold' => 1]);
    $ids = null;
    foreach ([false, true, false] as $unavailable) {
        DB::table('crawl_collections')->delete();
        DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), $unavailable && $failure === 'root-status'
            ? new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500))
            : new FetchResult(200, '<a href="'.$child.'">Child</a>', ['Content-Type' => ['text/html']]));
        if (! $unavailable || $failure !== 'root-status') {
            $fake->respondWith(crawlHttpRequest($child), $unavailable
                ? ($failure === 'child-timeout' ? new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout)) : new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500)))
                : new FetchResult(200, '<img src="http://fixtures.test/mixed-image">', ['Content-Type' => ['text/html'], ...($failure === 'child-redirect-status' ? ['X-Guzzle-Redirect-History' => ['https://fixtures.test/mixed-final']] : [])]));
        }
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        $baseline = app(CrawlPersistence::class)->baseline($check->id);
        expect(json_encode($baseline['artifact']->toArray(), JSON_THROW_ON_ERROR))->not->toContain('source_url');
        $current = $baseline['finding_ids'];
        expect($check->refresh()->latest_run_result)->toBe($unavailable && $failure === 'root-status' ? CheckResult::Errored : CheckResult::Failed)
            ->and($current)->toHaveCount(1)
            ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1)
            ->and(Event::query()->where('type', 'mixed_content.new_finding')->count())->toBe(0);
        if ($ids !== null) {
            expect($current)->toBe($ids);
        }
        $ids = $current;
        if ($unavailable && $failure !== 'root-status') {
            recordCrawl(crawlContext($check));
            expect(CheckRun::query()->where('check_id', $check->id)->orderByDesc('id')->first()->metrics['mixed_content'])->toBe(1);
        }
    }
})->with(['root-status', 'child-status', 'child-timeout', 'child-redirect-status']);

it('reports a root status error for both checks without inventing finding baselines', function (): void {
    $broken = crawlCheck(config: ['respect_robots' => false]);
    $mixed = Check::factory()->create(['site_id' => $broken->site_id, 'type' => CheckKey::MixedContent]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(crawlHttpRequest($broken->site->url), new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500))));
    recordCrawl(crawlContext($broken));
    recordCrawl(crawlContext($mixed));
    expect($broken->refresh()->latest_run_result)->toBe(CheckResult::Errored)
        ->and($broken->summary)->toBe('crawl.start_page_unreachable.http_status')
        ->and($broken->summary_params)->toBe(['status' => 500])
        ->and(json_decode(CheckRun::query()->where('check_id', $broken->id)->sole()->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe('Crawl start page unavailable. Outbound HTTP failed: http_status (HTTP 500).')
        ->and($mixed->refresh()->latest_run_result)->toBe(CheckResult::Errored)
        ->and($mixed->summary)->toBe('crawl.start_page_unreachable.http_status')
        ->and($mixed->summary_params)->toBe(['status' => 500])
        ->and(CheckRun::query()->where('check_id', $mixed->id)->count())->toBe(1)
        ->and(app(CrawlPersistence::class)->baseline($mixed->id))->toBeNull()
        ->and(Event::query()->where('type', 'mixed_content.new_finding')->count())->toBe(0);
});

it('canonicalizes slashless roots without altering query tokens or accepting root timeouts', function (string $raw, string $canonical, bool $timeout): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(microtime(true), 'UTC'));
    Queue::fake();
    $check = crawlCheck(url: $raw, config: ['respect_robots' => false, 'max_depth' => 0]);
    $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($canonical), $timeout ? new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout)) : new FetchResult(200, 'Root public marker', ['Content-Type' => ['text/html']]));
    app()->instance(OutboundHttp::class, $fake);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    app()->call(new RunCheck($check->id, $check->refresh()->claim_token, 300, 'checks-crawl')->handle(...));
    expect($check->refresh()->latest_run_result)->toBe($timeout ? CheckResult::Errored : CheckResult::Succeeded)
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]['request']->url)->toBe($canonical)
        ->and(DB::table('crawl_collections')->count())->toBe($timeout ? 0 : 1)
        ->and(DB::table('crawl_snapshots')->count())->toBe($timeout ? 0 : 1);
})->with([
    ['https://fixtures.test', 'https://fixtures.test/'],
    ['https://fixtures.test?token=query-secret', 'https://fixtures.test/?token=query-secret'],
])->with([false, true]);

it('checks public external status without inspecting it or spending the internal page budget', function (): void {
    $root = 'https://fixtures.test/external-scope';
    $a = 'https://fixtures.test/internal-a';
    $b = 'https://fixtures.test/internal-b';
    $external = 'https://external.fixtures.test/status-only';
    $port = 'https://fixtures.test:8443/status-only';
    $headers = [['name' => 'X-Site-Secret', 'value' => 'site-private-secret']];
    $sent = [];
    $responses = [
        $root => new Response(200, ['Content-Type' => 'text/html'], '<a href="'.$external.'">External</a><a href="'.$port.'">Port</a><a href="'.$a.'">Internal</a>'),
        $a => new Response(200, ['Content-Type' => 'text/html'], '<a href="'.$b.'">B</a>'),
        $b => new Response(200, ['Content-Type' => 'text/html'], '<p>Clean</p>'),
        $external => new Response(404),
        $port => new Response(200, ['Content-Type' => 'text/html'], '<img src="http://evil.test/image"><a href="https://fixtures.test/not-from-site">Nested</a>'),
    ];
    $transport = crawlMockTransport(array_values($responses), $sent);
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 3]);
    $mixed = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::MixedContent]);
    $context = crawlContext($check, $headers);
    $mixedContext = crawlContext($mixed, $headers);
    ClosedGuardedHttp::run(['fixtures.test' => [['8.8.8.8']], 'external.fixtures.test' => [['1.1.1.1']]], $transport, function () use ($check, $context, $mixed, $mixedContext, $b, $external, &$sent, $transport): void {
        recordCrawl($context);
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Warning)
            ->and(DB::table('crawl_findings')->where('crawled_url', $b)->value('status_code'))->toBe(200)
            ->and(DB::table('crawl_findings')->where('crawled_url', $external)->value('status_code'))->toBe(404)
            ->and(DB::table('crawl_findings')->where('crawled_url', 'https://fixtures.test/not-from-site')->exists())->toBeFalse();
        recordCrawl($mixedContext);
        expect($mixed->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
            ->and($sent)->toHaveCount(5)
            ->and($transport->count())->toBe(0);
        foreach (array_slice($sent, 3) as $request) {
            expect($request->hasHeader('X-Site-Secret'))->toBeFalse()
                ->and($request->getHeaderLine('Accept'))->toBe('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');
        }
    });
});

it('strips site headers on a guarded public redirect without crawling the external response', function (): void {
    $root = 'https://fixtures.test/public-redirect-root';
    $hop = 'https://fixtures.test/public-redirect-child';
    $target = 'https://external.fixtures.test/header-check';
    $sent = [];
    $responses = [
        $root => new Response(200, ['Content-Type' => 'text/html'], '<a href="'.$hop.'">Redirect</a>'),
        $hop => new Response(302, ['Location' => $target]),
        $target => new Response(200, ['Content-Type' => 'text/html'], '<a href="https://fixtures.test/from-external">Do not follow</a><img src="http://evil.test/image">'),
    ];
    $transport = crawlMockTransport(array_values($responses), $sent);
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $context = crawlContext($check, [['name' => 'X-Crawler-Secret', 'value' => 'private-token']]);
    ClosedGuardedHttp::run(['fixtures.test' => [['8.8.8.8']], 'external.fixtures.test' => [['1.1.1.1']]], $transport, function () use ($check, $context, $root, $hop, $target, &$sent, $transport): void {
        recordCrawl($context);
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
            ->and(DB::table('crawl_findings')->where('crawled_url', $hop)->value('status_code'))->toBe(200)
            ->and(array_map(static fn (RequestInterface $request): string => (string) $request->getUri(), $sent))->toBe([$root, $hop, $target])
            ->and($sent[0]->getHeaderLine('X-Crawler-Secret'))->toBe('private-token')
            ->and($sent[2]->hasHeader('X-Crawler-Secret'))->toBeFalse()
            ->and(DB::table('mixed_content_findings')->count())->toBe(0)
            ->and($transport->count())->toBe(0);
    });
});

it('retains unchecked external findings when the status check bound is reached', function (): void {
    $root = 'https://fixtures.test/external-bound';
    $a = 'https://external.fixtures.test/a';
    $b = 'https://external.fixtures.test/b';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    foreach ([5000, 1, 5000] as $limit) {
        config(['heptapod.crawl.max_status_urls' => $limit]);
        DB::table('crawl_collections')->delete();
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$a.'">A</a><a href="'.$b.'">B</a>', ['Content-Type' => ['text/html']]));
        foreach (array_slice([$a, $b], 0, $limit) as $url) {
            $fake->respondWith(crawlHttpRequest($url), $url === $a ? new FetchResult(200) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
        }
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Warning)
            ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toHaveCount(1)
            ->and($fake->requests)->toHaveCount($limit === 1 ? 2 : 3)
            ->and(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe(0);
    }
});

it('keeps partial findings and prior broken links without change events when crawl time runs out', function (): void {
    $root = 'https://fixtures.test/time-budget';
    $old = 'https://fixtures.test/old-broken';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check->update(['failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$old.'">Old</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($old), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    recordCrawl(crawlContext($check));
    $priorIds = app(CrawlPersistence::class)->baseline($check->id)['finding_ids'];
    DB::table('crawl_collections')->delete();
    $context = crawlContext($check);
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request) use ($root, $old, $context): FetchResult {
        if ($request->url === $old) {
            CarbonImmutable::setTestNow($context->deadline->addSecond());

            return new FetchResult(200);
        }
        expect($request->url)->toBe($root);

        return new FetchResult(200, '<a href="http://exa mple/new">New invalid</a><a href="'.$old.'">Recovered</a>', ['Content-Type' => ['text/html']]);
    });
    app()->instance(OutboundHttp::class, $fake);
    try {
        recordCrawl($context);
        $baseline = app(CrawlPersistence::class)->baseline($check->id);
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
            ->and($check->summary)->toBe('broken_links.incomplete_time_limit')
            ->and($baseline['artifact']->time_limit_reached)->toBeTrue()
            ->and($baseline['artifact']->discovery_complete)->toBeFalse()
            ->and($baseline['finding_ids'])->toContain(...$priorIds)
            ->and(DB::table('crawl_findings')->where('snapshot_id', DB::table('crawl_snapshots')->where('run_id', $context->runId)->value('id'))->where('error_message', 'invalid URL')->count())->toBe(1)
            ->and(DB::table('crawl_findings')->where('snapshot_id', DB::table('crawl_snapshots')->where('run_id', $context->runId)->value('id'))->where('crawled_url', $old)->pluck('status_code')->all())->toContain(200, 404)
            ->and(CheckRun::query()->where('check_id', $check->id)->orderByDesc('id')->first()->evidence['time_limit_reached'])->toBeTrue()
            ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1)
            ->and(Event::query()->whereIn('type', ['incident.resolved', 'broken_links.new_broken_found'])->count())->toBe(0)
            ->and($fake->requests)->toHaveCount(2);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('records an incomplete mixed-content observation when the deadline expires before the start', function (): void {
    $settings = crawlCheck(url: 'https://fixtures.test/time-budget', config: ['respect_robots' => false]);
    $check = Check::factory()->create(['site_id' => $settings->site_id, 'type' => CheckKey::MixedContent]);
    $context = crawlContext($check);
    app()->instance(OutboundHttp::class, $fake = new FakeOutboundHttp);
    CarbonImmutable::setTestNow($context->deadline->addSecond());
    try {
        recordCrawl($context);
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Pending)
            ->and(CheckRun::query()->where('check_id', $check->id)->sole()->result)->toBe(RunResult::Succeeded)
            ->and(CheckRun::query()->where('check_id', $check->id)->sole()->evidence['verified_nothing'])->toBeTrue()
            ->and($check->summary)->toBe('mixed_content.incomplete_time_limit')
            ->and(app(CrawlPersistence::class)->baseline($check->id)['artifact']->time_limit_reached)->toBeTrue()
            ->and(CheckRun::query()->where('check_id', $check->id)->sole()->evidence['time_limit_reached'])->toBeTrue()
            ->and($fake->requests)->toBe([]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('reports the time limit, not the exclusion, when Always crawl forces an excluded start URL', function (): void {
    $settings = crawlCheck(url: 'https://fixtures.test/time-budget', config: ['respect_robots' => false, 'exclude_patterns' => ['*'], 'force_crawl_urls' => ['https://fixtures.test/time-budget']]);
    $check = Check::factory()->create(['site_id' => $settings->site_id, 'type' => CheckKey::MixedContent]);
    $context = crawlContext($check);
    app()->instance(OutboundHttp::class, $fake = new FakeOutboundHttp);
    CarbonImmutable::setTestNow($context->deadline->addSecond());
    try {
        recordCrawl($context);
        expect($check->refresh()->summary)->toBe('mixed_content.incomplete_time_limit')
            ->and(app(CrawlPersistence::class)->baseline($check->id)['artifact']->not_crawlable_reason)->toBeNull()
            ->and($fake->requests)->toBe([]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('retains confirmed external findings when the deadline expires during a status check', function (): void {
    $root = 'https://fixtures.test/external-deadline';
    $external = 'https://external.fixtures.test/slow-broken';
    $check = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    foreach ([false, true, false] as $expired) {
        $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
        DB::table('crawl_collections')->delete();
        $claimed = crawlContext($check);
        $context = $expired ? new CheckContext($claimed->siteId, $claimed->checkId, $claimed->runId, $claimed->claimToken, $claimed->url,
            // The root fetch, parse and collection locks must finish before this deadline; 2 s leaves a wide margin on a loaded CI shard.
            $claimed->startedAt, CarbonImmutable::createFromTimestamp(microtime(true) + 2.0, 'UTC'),
            $claimed->config, $claimed->crawlerHeaders, $claimed->previousResult) : $claimed;
        $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request) use ($root, $external, $expired, $context): FetchResult {
            if ($request->url === $root) {
                return new FetchResult(200, '<a href="'.$external.'">External</a>', ['Content-Type' => ['text/html']]);
            }
            expect($request->url)->toBe($external);
            if ($expired) {
                $waitUntil = $context->deadline->getTimestampMs() / 1000 + 0.5;
                $waitCap = microtime(true) + 5;
                while (microtime(true) < $waitUntil) {
                    if (microtime(true) >= $waitCap) {
                        throw new RuntimeException('Deadline condition was not reached within five seconds.');
                    }
                    usleep(10_000);
                }

                return new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout));
            }

            return new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404));
        });
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl($context);
        $baseline = app(CrawlPersistence::class)->baseline($check->id);
        $run = CheckRun::query()->where('check_id', $check->id)->latest('id')->first();
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Warning)
            ->and($baseline['finding_ids'])->toBe([hash('sha256', $external)])
            ->and($run->evidence['time_limit_reached'])->toBe($expired)
            ->and($run->summary)->toBe($expired ? 'broken_links.incomplete_time_limit' : 'broken_links.warning')
            ->and(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe(0)
            ->and($fake->requests)->toHaveCount(2);
        if ($expired) {
            $finding = DB::table('crawl_findings')->where('snapshot_id', DB::table('crawl_snapshots')->where('run_id', $run->id)->value('id'))->where('crawled_url', $external)->sole();
            expect($finding->status_code)->toBe(404)
                ->and($finding->error_message)->toBe('request_failed')
                ->and($finding->current_error_message)->toBeNull()
                ->and($finding->carried_forward)->toBeTrue();
        }
    }
});

it('matches supported rel tokens across HTML whitespace without substring matches', function (string $rel, int $expected): void {
    $settings = crawlCheck(config: ['respect_robots' => false, 'types' => ['link']]);
    $check = Check::factory()->create(['site_id' => $settings->site_id, 'type' => CheckKey::MixedContent]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(crawlHttpRequest($check->site->url), new FetchResult(200, '<link rel="'.$rel.'" href="http://fixtures.test/style.css">', ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    expect(DB::table('mixed_content_findings')->count())->toBe($expected)
        ->and($check->refresh()->latest_run_result)->toBe($expected > 0 ? CheckResult::Failed : CheckResult::Succeeded);
})->with([
    ['alternate StyleSheet', 1],
    ["other\tpreload\r\nnext", 1],
    'form-feed whitespace' => ["modulepreload\x0Calternate", 1],
    ['alternate icon', 1],
    ['notstylesheet notpreload', 0],
]);

it('carries child broken findings through an unavailable root without rediscovery', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'types' => ['link']]);
    $root = $check->site->url;
    $child = 'https://fixtures.test/root-unavailable-child';
    foreach ([200, 500, 200] as $status) {
        DB::table('crawl_collections')->delete();
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), $status === 500
            ? new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500))
            : new FetchResult(200, '<a href="'.$child.'">Child</a>', ['Content-Type' => ['text/html']]));
        if ($status === 200) {
            $fake->respondWith(crawlHttpRequest($child), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
        }
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        expect($check->refresh()->latest_run_result)->toBe($status === 500 ? CheckResult::Errored : CheckResult::Failed)
            ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toHaveCount(1)
            ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1);
    }
    expect(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe(0);
});

it('keeps external status checks on noindex follow pages at their traversal depth', function (): void {
    $root = 'https://fixtures.test/noindex-root';
    $child = 'https://fixtures.test/noindex-child';
    $external = 'https://external.fixtures.test/noindex-broken';
    $check = crawlCheck(url: $root, config: ['respect_robots' => true, 'types' => ['link'], 'max_depth' => 2]);
    $ids = null;
    foreach ([false, true, false] as $noindex) {
        DB::table('crawl_collections')->delete();
        $fake = (new FakeOutboundHttp)
            ->respondWith(crawlHttpRequest('https://fixtures.test/robots.txt'), new FetchResult(404))
            ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$child.'">Child</a>', ['Content-Type' => ['text/html']]))
            ->respondWith(crawlHttpRequest($child), new FetchResult(200, ($noindex ? '<meta name="robots" content="noindex,follow">' : '').'<a href="'.$external.'">External</a>', ['Content-Type' => ['text/html']]))
            ->respondWith(crawlHttpRequest($external), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        $current = app(CrawlPersistence::class)->baseline($check->id)['finding_ids'];
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Warning)
            ->and($current)->toHaveCount(1)
            ->and($fake->requests)->toHaveCount(4)
            ->and(Event::query()->where('type', 'broken_links.new_broken_found')->count())->toBe(0);
        if ($ids !== null) {
            expect($current)->toBe($ids);
        }
        $ids = $current;
    }
});

it('normalizes accepted findings under current secrets before carry and comparison', function (CheckKey $key, string $state): void {
    $root = 'https://fixtures.test/current-secret';
    $child = 'https://fixtures.test/second-secret-child';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link'], 'max_pages' => 2]);
    $check = $key === CheckKey::BrokenLinks ? $settings : Check::factory()->create(['site_id' => $settings->site_id, 'type' => $key]);
    foreach ([false, true] as $changed) {
        DB::table('crawl_collections')->delete();
        DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
        $headers = $changed ? ['X-Crawl-Secret' => ['second-secret']] : [];
        $limit = $changed && $state === 'unobserved' ? 1 : 2;
        $settings->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => $limit])]);
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root, $headers), new FetchResult(200, '<a href="'.$child.'">Child</a>', ['Content-Type' => ['text/html']]));
        if ($limit > 1) {
            $recovered = $changed && $state === 'recovered';
            $fake->respondWith(crawlHttpRequest($child, $headers), $key === CheckKey::BrokenLinks
                ? ($recovered ? new FetchResult(200) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)))
                : new FetchResult(200, $recovered ? 'Public' : '<img src="http://fixtures.test/second-secret-image">', ['Content-Type' => ['text/html']]));
        }
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check, $changed ? [['name' => 'X-Crawl-Secret', 'value' => 'second-secret']] : []));
    }
    $baseline = app(CrawlPersistence::class)->baseline($check->id);
    expect($check->refresh()->latest_run_result)->toBe($state === 'recovered' ? CheckResult::Succeeded : CheckResult::Failed)
        ->and(json_encode($baseline['artifact']->privateStorage(), JSON_THROW_ON_ERROR))->not->toContain('second-secret')
        ->and($baseline['finding_ids'])->toHaveCount($state === 'recovered' ? 0 : 1)
        ->and(Event::query()->whereIn('type', ['broken_links.new_broken_found', 'mixed_content.new_finding'])->count())->toBe(0);
})->with([CheckKey::BrokenLinks, CheckKey::MixedContent])->with(['recovered', 'unchanged', 'unobserved']);

it('prunes out of scope findings only when discovery is complete', function (CheckKey $key, string $phase): void {
    $root = 'https://fixtures.test/scope-root';
    $old = 'https://fixtures.test/old-scope';
    $stable = 'https://fixtures.test/stable-scope';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = $key === CheckKey::BrokenLinks ? $settings : Check::factory()->create(['site_id' => $settings->site_id, 'type' => $key]);
    foreach ([false, true] as $changed) {
        DB::table('crawl_collections')->delete();
        DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
        $limit = $changed && $phase === 'complete-cap' ? 2 : 500;
        $settings->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => $limit])]);
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), $changed && $phase === 'root-unavailable'
            ? new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500))
            : new FetchResult(200, '<a href="'.$stable.'">Stable</a>'.($changed ? '<a href="https://fixtures.test/new-scope">New</a>' : '<a href="'.$old.'">Old</a>'), ['Content-Type' => ['text/html']]));
        if (! $changed || $phase !== 'root-unavailable') {
            foreach ($changed ? [$stable] : [$stable, $old] as $url) {
                $fake->respondWith(crawlHttpRequest($url), $changed && $phase === 'timeout'
                    ? new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout))
                    : ($key === CheckKey::BrokenLinks ? ($changed && str_starts_with($phase, 'complete') ? new FetchResult(200) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))) : new FetchResult(200, '<img src="http://fixtures.test/scope-image">', ['Content-Type' => ['text/html']])));
            }
            if ($changed && $phase !== 'complete-cap') {
                $fake->respondWith(crawlHttpRequest('https://fixtures.test/new-scope'), new FetchResult(200));
            }
        }
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
    }
    $baseline = app(CrawlPersistence::class)->baseline($check->id);
    $values = $key === CheckKey::BrokenLinks ? array_column($baseline['artifact']->urls, 'crawled_url') : array_column($baseline['artifact']->mixed_content, 'source_url');
    if ($phase === 'complete') {
        expect($values)->not->toContain($old);
    } else {
        expect($values)->toContain($old);
    }
    expect($check->refresh()->latest_run_result)->toBe($phase === 'root-unavailable' ? CheckResult::Errored : ($key === CheckKey::BrokenLinks && $phase === 'complete' ? CheckResult::Succeeded : CheckResult::Failed))
        ->and(Event::query()->whereIn('type', ['broken_links.new_broken_found', 'mixed_content.new_finding'])->count())->toBe(0);
})->with([CheckKey::BrokenLinks, CheckKey::MixedContent])->with(['complete', 'complete-cap', 'timeout', 'root-unavailable']);

it('keeps an open finding baseline and incident when discovery exceeds the comparison limit', function (CheckKey $key, bool $external): void {
    $root = 'https://fixtures.test/overflow-root';
    $child = $external ? 'https://external.fixtures.test/overflow-old' : 'https://fixtures.test/overflow-old';
    $request = crawlHttpRequest($child);
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = $key === CheckKey::BrokenLinks ? $settings : Check::factory()->create(['site_id' => $settings->site_id, 'type' => $key, 'failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$child.'">Old</a>', ['Content-Type' => ['text/html']]))
        ->respondWith($request, $key === CheckKey::BrokenLinks ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200, '<img src="http://fixtures.test/overflow-image">', ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $baseline = app(CrawlPersistence::class)->baseline($check->id);
    $events = Event::query()->count();
    $settings->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => 1])]);
    DB::table('crawl_collections')->delete();
    DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
    $body = implode('', array_map(static fn (int $index): string => '<a href="/overflow-new-'.$index.'">New</a>', range(1, 25000)));
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), new FetchResult(200, $body, ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe($external ? CheckResult::Warning : CheckResult::Failed)
        ->and($check->summary)->toBe($key->value.'.comparison_limit')
        ->and(CheckRun::query()->where('check_id', $check->id)->orderByDesc('id')->first()->evidence['comparison_limit_reached'])->toBeTrue()
        ->and(app(CrawlPersistence::class)->baseline($check->id)['run_id'])->toBe($baseline['run_id'])
        ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toBe($baseline['finding_ids'])
        ->and(Event::query()->count())->toBe($events)
        ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe($external ? 0 : 1)
        ->and(CheckRun::query()->where('check_id', $check->id)->orderByDesc('id')->first()->metrics[$key === CheckKey::BrokenLinks ? 'broken_internal' : 'mixed_content'])->toBe(0);
    foreach (['en', 'de'] as $locale) {
        expect(__($check->summary, ['limit' => 25000], $locale))->not->toBe($check->summary);
    }
})->with([[CheckKey::BrokenLinks, false], [CheckKey::BrokenLinks, true], [CheckKey::MixedContent, false]]);

it('preserves original row indexes when validating batched findings', function (): void {
    $check = crawlCheck(config: ['respect_robots' => false, 'max_depth' => 0]);
    recordCrawl(crawlContext($check));
    $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
    $rows = [...$artifact->privateStorage(), 'evaluated_finding_ids' => array_fill(0, 1001, str_repeat('a', 64))];
    $rows['evaluated_finding_ids'][1000] = 'invalid';
    $context = crawlContext($check);
    try {
        app(CrawlPersistence::class)->stage($context, 'artifact', [$rows]);
        $this->fail('The invalid later row must be rejected.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['evaluated_finding_ids.1000']);
    }
    expect(DB::table('crawl_snapshots')->where('run_id', $context->runId)->exists())->toBeFalse();
});

it('keeps the baseline when carried rows exceed the comparison ceiling', function (CheckKey $key): void {
    $root = 'https://fixtures.test/carried-overflow';
    $child = 'https://fixtures.test/carried-old';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = $key === CheckKey::BrokenLinks ? $settings : Check::factory()->create(['site_id' => $settings->site_id, 'type' => $key, 'failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$child.'">Old</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest($child), $key === CheckKey::BrokenLinks ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200, '<img src="http://fixtures.test/carried-image">', ['Content-Type' => ['text/html']])));
    recordCrawl(crawlContext($check));
    $artifact = app(CrawlPersistence::class)->baseline($check->id)['artifact'];
    $rows = $artifact->privateStorage();
    unset($artifact);
    $memoryBefore = memory_get_usage(true);
    memory_reset_peak_usage();
    if ($key === CheckKey::BrokenLinks) {
        $sample = $rows['urls'][1];
        $ids = [hash('sha256', $sample['crawled_url'])];
        for ($index = 2; $index < 25000; $index++) {
            $finding = $sample;
            $finding['crawled_url'] = 'https://fixtures.test/carried-'.$index;
            $finding['relative_crawled_url'] = '/carried-'.$index;
            $rows['urls'][] = $finding;
            $ids[] = hash('sha256', $finding['crawled_url']);
        }
    } else {
        $sample = $rows['mixed_content'][0];
        $ids = [MixedContentFinding::from($sample)->identity()];
        for ($index = 1; $index < 25000; $index++) {
            $finding = $sample;
            $finding['mixed_content_url'] = 'http://fixtures.test/carried-image-'.$index;
            $rows['mixed_content'][] = $finding;
            $ids[] = MixedContentFinding::from($finding)->identity();
        }
    }
    $context = crawlContext($check);
    app(CrawlPersistence::class)->stage($context, 'artifact', [[...$rows, 'evaluated_finding_ids' => $ids]]);
    unset($rows, $sample, $finding, $ids);
    $outcome = new CheckOutcome(RunResult::Failed, $key->value.'.failed', [], new CrawlMetrics(25000, 25000, 0, $key === CheckKey::BrokenLinks ? 24999 : 0, 0, $key === CheckKey::MixedContent ? 25000 : 0));
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome))->accepted)->toBeTrue();
    $baseline = app(CrawlPersistence::class)->baseline($check->id);
    unset($baseline['artifact']);
    $events = Event::query()->count();
    DB::table('crawl_collections')->delete();
    DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)
        ->respondWith(crawlHttpRequest($root), new FetchResult(200, '<img src="http://fixtures.test/new-root-image"><a href="https://fixtures.test/new-timeout">New</a>', ['Content-Type' => ['text/html']]))
        ->respondWith(crawlHttpRequest('https://fixtures.test/new-timeout'), new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout))));
    recordCrawl(crawlContext($check));
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and($check->summary)->toBe($key->value.'.comparison_limit')
        ->and(app(CrawlPersistence::class)->baseline($check->id)['run_id'])->toBe($baseline['run_id'])
        ->and(app(CrawlPersistence::class)->baseline($check->id)['finding_ids'])->toBe($baseline['finding_ids'])
        ->and(Event::query()->count())->toBe($events)
        ->and(memory_get_peak_usage(true) - $memoryBefore)->toBeLessThan(144 * 1024 * 1024);
})->with([CheckKey::BrokenLinks, CheckKey::MixedContent]);

it('combines a page cap root failure and redirected child without losing mixed evidence', function (): void {
    $root = 'https://fixtures.test/combined-root';
    $child = 'https://fixtures.test/second-secret-alias';
    $final = 'https://fixtures.test/combined-final';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = Check::factory()->create(['site_id' => $settings->site_id, 'type' => CheckKey::MixedContent, 'failure_threshold' => 1]);
    $ids = null;
    foreach (['initial', 'cap', 'root-failure', 'child-failure', 'returned'] as $phase) {
        $limit = $phase === 'cap' ? 1 : 500;
        $settings->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => $limit])]);
        DB::table('crawl_collections')->delete();
        DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
        $headers = $phase === 'initial' ? [] : ['X-Crawl-Secret' => ['second-secret']];
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root, $headers), $phase === 'root-failure'
            ? new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500))
            : new FetchResult(200, '<a href="'.$child.'">Alias</a>', ['Content-Type' => ['text/html']]));
        if (! in_array($phase, ['cap', 'root-failure'], true)) {
            $fake->respondWith(crawlHttpRequest($child, $headers), $phase === 'child-failure'
                ? new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500))
                : new FetchResult(200, '<img src="http://fixtures.test/combined-image">', ['Content-Type' => ['text/html'], 'X-Guzzle-Redirect-History' => [$final]]));
        }
        app()->instance(OutboundHttp::class, $fake);
        $secrets = $phase === 'initial' ? [] : [['name' => 'X-Crawl-Secret', 'value' => 'second-secret']];
        if ($phase === 'root-failure') {
            recordCrawl(crawlContext($settings, $secrets));
            expect($settings->refresh()->latest_run_result)->toBe(CheckResult::Errored)
                ->and($settings->summary)->toBe('crawl.start_page_unreachable.http_status')
                ->and($settings->summary_params)->toBe(['status' => 500]);
        }
        recordCrawl(crawlContext($check, $secrets));
        $baseline = app(CrawlPersistence::class)->baseline($check->id);
        expect($check->refresh()->latest_run_result)->toBe($phase === 'root-failure' ? CheckResult::Errored : CheckResult::Failed)
            ->and($baseline['finding_ids'])->toHaveCount(1)
            ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1)
            ->and(Event::query()->where('type', 'mixed_content.new_finding')->count())->toBe(0);
        if ($ids !== null) {
            expect($baseline['finding_ids'])->toBe($ids)
                ->and(json_encode($baseline['artifact']->privateStorage(), JSON_THROW_ON_ERROR))->not->toContain('second-secret');
        }
        $ids = $baseline['finding_ids'];
    }
});

it('retains unseen descendant findings until internal traversal actually completes', function (CheckKey $key, string $interruption): void {
    $root = 'https://fixtures.test/nested-root';
    $middle = 'https://fixtures.test/nested-middle';
    $leaf = 'https://fixtures.test/nested-leaf';
    $settings = crawlCheck(url: $root, config: ['respect_robots' => false, 'types' => ['link']]);
    $check = $key === CheckKey::BrokenLinks ? $settings : Check::factory()->create(['site_id' => $settings->site_id, 'type' => $key, 'failure_threshold' => 1]);
    $leafIds = null;
    foreach ([false, true, false] as $interrupted) {
        DB::table('crawl_collections')->delete();
        DB::table('crawl_snapshots')->update(['collected_at' => now()->subDays(2)]);
        $limit = $interrupted && $interruption === 'cap' ? 1 : 500;
        $settings->update(['config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'max_pages' => $limit])]);
        $fake = (new FakeOutboundHttp)->respondWith(crawlHttpRequest($root), new FetchResult(200, '<a href="'.$middle.'">Middle</a>', ['Content-Type' => ['text/html']]));
        if ($limit > 1) {
            $fake->respondWith(crawlHttpRequest($middle), $interrupted
                ? ($interruption === 'transport' ? new FetchResult(failure: new FetchFailure(FetchFailureKind::Connect)) : new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500)))
                : new FetchResult(200, '<a href="'.$leaf.'">Leaf</a>', ['Content-Type' => ['text/html']]));
            if (! $interrupted) {
                $fake->respondWith(crawlHttpRequest($leaf), $key === CheckKey::BrokenLinks
                    ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))
                    : new FetchResult(200, '<img src="http://fixtures.test/nested-image">', ['Content-Type' => ['text/html']]));
            }
        }
        app()->instance(OutboundHttp::class, $fake);
        recordCrawl(crawlContext($check));
        $baseline = app(CrawlPersistence::class)->baseline($check->id);
        expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed)
            ->and(DB::table('incidents')->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(1);
        if ($leafIds === null) {
            $leafIds = $baseline['finding_ids'];
            expect($leafIds)->toHaveCount(1);
        }
        foreach ($leafIds as $id) {
            expect($baseline['finding_ids'])->toContain($id);
        }
        if ($interrupted) {
            expect($baseline['artifact']->discovery_complete)->toBeFalse();
        } else {
            expect($baseline['finding_ids'])->toBe($leafIds);
        }
    }
    expect(Event::query()->whereIn('type', ['broken_links.new_broken_found', 'mixed_content.new_finding'])->count())->toBe($key === CheckKey::BrokenLinks && $interruption === 'http-error' ? 1 : 0);
})->with([CheckKey::BrokenLinks, CheckKey::MixedContent])->with(['cap', 'http-error', 'transport']);

it('names the classification of every exported broken-links and mixed-content row', function (): void {
    $snapshotFor = function (CheckKey $type): array {
        $check = Check::factory()->create(['type' => $type]);
        $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'broken_links.failed',
            'started_at' => CarbonImmutable::parse('2026-10-28T11:00:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T11:02:00Z')]);
        $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $check->site_id, 'check_id' => $check->id, 'run_id' => $run->id,
            'claim_token' => (string) Str::uuid(), 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at, 'settings' => '{}', 'truncated' => false,
            'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]', 'discovery_complete' => true, 'evaluated_finding_ids' => '[]',
            'site_url' => 'https://export.example.test', 'target_fingerprint' => str_repeat('a', 64), 'classification_policy_version' => null]);

        return [$check, $run, $snapshot];
    };
    [$links, $linksRun, $linksSnapshot] = $snapshotFor(CheckKey::BrokenLinks);
    foreach ([['/gone', 404, null], ['/bot', 403, 'bot_protection'], ['/slow', null, 'unchecked (timeout)']] as [$path, $status, $error]) {
        DB::table('crawl_findings')->insert(['snapshot_id' => $linksSnapshot, 'crawled_url' => 'https://export.example.test'.$path, 'relative_crawled_url' => $path,
            'status_code' => $status, 'error_message' => $error, 'type' => 'link', 'internal' => true]);
    }
    [$mixed, $mixedRun, $mixedSnapshot] = $snapshotFor(CheckKey::MixedContent);
    DB::table('mixed_content_findings')->insert(['snapshot_id' => $mixedSnapshot, 'element_name' => 'img', 'mixed_content_url' => 'http://cdn.example.test/a.png',
        'source_url' => 'https://export.example.test/', 'found_on_url' => 'https://export.example.test/']);
    DB::table('crawl_findings')->insert(['snapshot_id' => $mixedSnapshot, 'crawled_url' => 'https://export.example.test/blocked', 'relative_crawled_url' => '/blocked',
        'status_code' => 403, 'error_message' => 'bot_protection', 'type' => 'link', 'internal' => true]);
    $persistence = app(CrawlPersistence::class);
    $statuses = static fn (iterable $rows): array => array_map(static fn (array $row): array => [$row['url'], $row['status']->value], [...$rows]);

    DB::transaction(function () use ($persistence, $statuses, $links, $linksRun, $mixed, $mixedRun): void {
        expect($statuses($persistence->exportFindings(CheckKey::BrokenLinks, $links->id, $linksRun->reference())))->toBe([
            ['https://export.example.test/gone', 'broken'], ['https://export.example.test/bot', 'not_verifiable'], ['https://export.example.test/slow', 'unchecked'],
        ])->and($statuses($persistence->exportFindings(CheckKey::MixedContent, $mixed->id, $mixedRun->reference())))->toBe([
            ['http://cdn.example.test/a.png', 'broken'], ['https://export.example.test/blocked', 'not_verifiable'],
        ]);
    });
});
