<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri as PsrUri;
use Heptapod\Addons\Sitemap\SitemapPersistence;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PublicRequestProfile;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Support\Api\ApiHeadline;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    Sleep::fake(syncWithCarbon: true);
});

/** @param array<string, mixed> $config */
function sitemapContext(array $config = [], ?CheckContext $previous = null): CheckContext
{
    $site = $previous instanceof CheckContext ? Site::findOrFail($previous->siteId) : Site::factory()->create(['url' => getenv('SITEMAP_FIXTURE_URL') ?: 'http://fixtures.test']);
    $check = $previous instanceof CheckContext ? Check::findOrFail($previous->checkId) : Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Sitemap,
        'config' => app(CheckRegistry::class)->get(CheckKey::Sitemap)->definition()->configSchema->validateConfig($config)]);
    $at = CarbonImmutable::now();

    return new CheckContext($site->id, $check->id, (int) DB::scalar("SELECT nextval('check_runs_id_seq')"), (string) Str::uuid(), $site->url, $at, $at->addMinutes(5), $config, [], null);
}

function acceptSitemap(CheckContext $context): void
{
    $check = Check::query()->findOrFail($context->checkId);
    $context = ClaimedRun::start($check, $context->startedAt, $context->runId, $context->config, $context->crawlerHeaders);
    $outcome = app(CheckRegistry::class)->get(CheckKey::Sitemap)->execute($context);
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($context->checkId, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome))->accepted)->toBeTrue();
}

function runSitemap(CheckContext $context): CheckRun
{
    Queue::fake();
    $check = Check::findOrFail($context->checkId);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    app()->call(new RunCheck($check->id, $check->refresh()->claim_token, 300, 'checks-crawl')->handle(...));

    return CheckRun::query()->latest('id')->firstOrFail();
}

/** @param list<string> $urls */
function sitemapXml(array $urls, bool $index = false): string
{
    $root = $index ? 'sitemapindex' : 'urlset';
    $tag = $index ? 'sitemap' : 'url';

    return '<'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', array_map(static fn (string $url): string => '<'.$tag.'><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></'.$tag.'>', $urls)).'</'.$root.'>';
}

function sitemapRequest(string $path = '/sitemap.xml', string $method = 'GET', bool $operator = true, int $maxBytes = 2097152): FetchRequest
{
    return sitemapLibraryRequest(new FetchRequest('http://fixtures.test'.$path, $method, maxWireBytes: $maxBytes, maxDecodedBytes: $maxBytes,
        redirects: $operator ? RedirectPolicy::Operator : RedirectPolicy::Untrusted, siteHost: 'fixtures.test', timeoutSeconds: 10.0, acceptedStatuses: range(200, 599)));
}

/** Library integration fakes observe the public request profile, PSR-generated Host and an empty stream body. */
function sitemapLibraryRequest(FetchRequest $request): FetchRequest
{
    $message = new Request($request->method, $request->url, $request->headers, $request->body);
    $headers = $message->getHeaders();
    foreach (array_reverse(PublicRequestProfile::headers()) as $name => $value) {
        if (! $message->hasHeader($name)) {
            $headers = [$name => [$value]] + $headers;
        }
    }

    return new FetchRequest($request->url, $request->method, $headers, (string) $message->getBody(), $request->maxWireBytes, $request->maxDecodedBytes,
        $request->redirects, $request->maxRedirects, $request->siteHost, $request->timeoutSeconds, $request->acceptedStatuses, $request->strictRedirects, $request->withCertificateInfo, $request->publicTargetsOnly);
}

/** @return array<string, list<string>> */
function sitemapPublicProfile(): array
{
    return array_map(static fn (string $value): array => [$value], PublicRequestProfile::headers());
}

function fakeSitemap(string $body, int $maxBytes = 2097152): FakeOutboundHttp
{
    $fake = (new FakeOutboundHttp)->respondWith(sitemapRequest(maxBytes: $maxBytes), new FetchResult(200, $body));
    app()->instance(OutboundHttp::class, $fake);

    return $fake;
}

it('runs through the seam and engine with guarded child sitemaps and HEAD to GET fallback', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $fake = fakeSitemap(sitemapXml(['http://fixtures.test/child.xml'], true))
        ->respondWith(sitemapRequest('/child.xml', operator: false), new FetchResult(200, sitemapXml(['http://fixtures.test/page'])))
        ->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(405, failure: new FetchFailure(FetchFailureKind::HttpStatus, 405)))
        ->respondWith(sitemapRequest('/page', operator: false), new FetchResult(200, 'page'));

    $run = runSitemap(sitemapContext());

    expect($run->result)->toBe(RunResult::Succeeded);
    expect($run->metrics)->toEqual(['totalIssuesCount' => 0, 'totalUrlCount' => 1, 'hasIssues' => false]);
    $row = DB::table('sitemap_snapshots')->sole();
    expect($row->run_id)->toBe($run->id);
    expect($row->run_ended_at)->not->toBeNull();
    expect(json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR))->toEqual([
        'checkUrl' => 'http://fixtures.test/sitemap.xml', 'totalIssuesCount' => 0, 'totalUrlCount' => 1, 'hasIssues' => false, 'issues' => [], 'notes' => [],
        'issue_counts' => [], 'not_verifiable' => [], 'rotation_next' => 0,
        'sitemapIndexes' => [['url' => 'http://fixtures.test/sitemap.xml', 'issues' => []]],
        'sitemaps' => [['url' => 'http://fixtures.test/child.xml', 'urlCount' => 1, 'checkedReachabilityOfAllUrls' => true, 'issuesCount' => 0, 'issues' => []]],
    ]);
    expect($fake->requests)->toHaveCount(4);
});

it('passes crawler headers only for the trusted site host and treats external child indexes as untrusted', function (): void {
    $headers = [['name' => 'X-Crawler-Key', 'value' => 'first'], ['name' => 'X-Crawler-Key', 'value' => 'second']];
    $fake = (new FakeOutboundHttp)->respondWith(sitemapLibraryRequest(new FetchRequest('http://fixtures.test/sitemap.xml', headers: ['x-crawler-key' => ['first', 'second']],
        maxWireBytes: 2097152, maxDecodedBytes: 2097152, redirects: RedirectPolicy::Operator, siteHost: 'fixtures.test', timeoutSeconds: 10.0, acceptedStatuses: range(200, 599))),
        new FetchResult(200, sitemapXml(['http://external.fixtures.test/child.xml'], true)))
        ->respondWith(sitemapLibraryRequest(new FetchRequest('http://external.fixtures.test/child.xml', maxWireBytes: 2097152, maxDecodedBytes: 2097152,
            redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test', timeoutSeconds: 10.0, acceptedStatuses: range(200, 599))), new FetchResult(200, sitemapXml(['http://external.fixtures.test/page'])))
        ->respondWith(sitemapLibraryRequest(new FetchRequest('http://external.fixtures.test/page', 'HEAD', maxWireBytes: 2097152, maxDecodedBytes: 2097152,
            redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test', timeoutSeconds: 10.0, acceptedStatuses: range(200, 599))), new FetchResult(200));
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext();
    $context = new CheckContext($context->siteId, $context->checkId, $context->runId, $context->claimToken, $context->url, $context->startedAt, $context->deadline, [], $headers, null);
    acceptSitemap($context);
    expect(CheckRun::query()->sole()->result)->toBe(RunResult::Succeeded);
    expect($fake->requests)->toHaveCount(3);
    expect(DB::table('sitemap_snapshots')->value('snapshot'))->not->toContain('first', 'second');
});

it('grants crawler headers only to discovered targets on the site origin', function (): void {
    $context = sitemapContext(['speed' => 'fastest']);
    $headers = ['x-crawler-key' => ['origin-secret42']];
    Site::findOrFail($context->siteId)->update(['url' => 'https://fixtures.test', 'crawler_headers' => [['name' => 'X-Crawler-Key', 'value' => 'origin-secret42']]]);
    $children = ['http://fixtures.test/child.xml', 'https://fixtures.test:8443/child.xml', 'https://fixtures.test/child.xml', 'https://fixtures.test:443/child.xml'];
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use ($children): FetchResult {
        if ($request->url === 'https://fixtures.test/sitemap.xml') {
            return new FetchResult(200, sitemapXml($children, true));
        }

        return $request->method === 'HEAD' ? new FetchResult(200) : new FetchResult(200, sitemapXml([str_replace('/child.xml', '/page', $request->url)]));
    });
    app()->instance(OutboundHttp::class, $fake);
    $run = runSitemap($context);
    $requests = [];
    foreach ($fake->requests as $request) {
        $requests[$request['request']->url] = $request['request']->headers;
    }

    $generated = [...sitemapPublicProfile(), 'Host' => ['fixtures.test']];
    $alternate = [...sitemapPublicProfile(), 'Host' => ['fixtures.test:8443']];
    expect($requests['http://fixtures.test/child.xml'])->toBe($generated);
    expect($requests['https://fixtures.test:8443/child.xml'])->toBe($alternate);
    expect($requests['http://fixtures.test/page'])->toBe($generated);
    expect($requests['https://fixtures.test:8443/page'])->toBe($alternate);
    expect($requests['https://fixtures.test/child.xml'])->toBe($generated + $headers);
    expect($requests['https://fixtures.test/page'])->toBe($generated + $headers);
    expect($requests[(string) new PsrUri('https://fixtures.test:443/child.xml')])->toBe($generated + $headers);
    expect($requests[(string) new PsrUri('https://fixtures.test:443/page')])->toBe($generated + $headers);
    expect($run->result)->toBe(RunResult::Succeeded);
    expect($run->metrics['totalUrlCount'])->toBe(3);
});

it('fails malformed XML and bare gzip bombs before any listed target is fetched', function (string $body, string $issue): void {
    $fake = fakeSitemap($body, 512);
    config(['sitemap.max_xml_bytes' => 512]);
    $context = sitemapContext();

    acceptSitemap($context);

    expect(CheckRun::query()->sole()->result)->toBe(RunResult::Failed);
    expect(app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'])->toEqual([['url' => 'http://fixtures.test/sitemap.xml', 'type' => $issue]]);
    expect($fake->requests)->toHaveCount(1);
})->with([
    'malformed' => ['<urlset><url>', 'sitemap.issue.invalid'],
    'wrong root' => ['<html><url><loc>http://fixtures.test/page</loc></url></html>', 'sitemap.issue.invalid'],
    'missing location' => ['<urlset><url/></urlset>', 'sitemap.issue.invalid'],
    'invalid scheme' => ['<urlset><url><loc>file:///etc/passwd</loc></url></urlset>', 'sitemap.issue.invalid'],
    'XXE' => ['<!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><urlset><url><loc>&xxe;</loc></url></urlset>', 'sitemap.issue.invalid'],
    'gzip bomb' => [gzencode(str_repeat(' ', 4096)), 'sitemap.issue.size_limit'],
    'truncated gzip' => [substr(gzencode('<urlset/>'), 0, -4), 'sitemap.issue.invalid'],
]);

it('maps typed operator fetch failures without confusing invalid configuration with target downtime', function (FetchFailureKind $kind, string $issue): void {
    $fake = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $fake);
    $status = $kind === FetchFailureKind::HttpStatus ? 404 : null;
    $fake->respondWith(sitemapRequest('/custom.xml'), new FetchResult($status, failure: new FetchFailure($kind, $status)));
    $context = sitemapContext(['path' => '/custom.xml']);

    $run = runSitemap($context);

    if ($kind === FetchFailureKind::InvalidRequest) {
        expect($run->result)->toBe(RunResult::Errored);
        expect($run->error)->toContain('invalid_request');
        expect(DB::table('sitemap_snapshots')->count())->toBe(0);
    } else {
        expect($run->result)->toBe(RunResult::Failed);
        if ($kind === FetchFailureKind::HttpStatus) {
            expect($run->summary)->toBe('sitemap.summary.not_found')
                ->and($run->summary_params)->toBe(['path' => 'custom.xml']);
        }
        expect(app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'])->toEqual([
            ['url' => 'http://fixtures.test/custom.xml', 'type' => $issue, 'kind' => $kind->value, 'status' => $status],
        ]);
    }
})->with([
    [FetchFailureKind::Timeout, 'sitemap.issue.unreachable'], [FetchFailureKind::Tls, 'sitemap.issue.unreachable'],
    [FetchFailureKind::Dns, 'sitemap.issue.unreachable'], [FetchFailureKind::Connect, 'sitemap.issue.unreachable'],
    [FetchFailureKind::HttpStatus, 'sitemap.issue.unreachable'], [FetchFailureKind::TooLarge, 'sitemap.issue.size_limit'],
    [FetchFailureKind::PrivateTarget, 'sitemap.issue.blocked'], [FetchFailureKind::RedirectBlocked, 'sitemap.issue.blocked'],
    [FetchFailureKind::TooManyRedirects, 'sitemap.issue.unreachable'],
    [FetchFailureKind::InvalidRequest, ''],
]);

it('reports HTTP statuses as sitemap issues with localized counts instead of engine errors', function (int $status, string $locale, string $headline, string $historyLabel, string $problem, string $target): void {
    app()->setLocale($locale);
    $failure = new FetchResult($status, failure: new FetchFailure(FetchFailureKind::HttpStatus, $status));
    $fake = $target === 'document'
        ? (new FakeOutboundHttp)->respondWith(sitemapRequest('/custom.xml'), $failure)
        : fakeSitemap(sitemapXml(['http://fixtures.test/page']))
            ->respondWith(sitemapRequest('/page', 'HEAD', false), $failure)
            ->respondWith(sitemapRequest('/page', operator: false), $failure);
    app()->instance(OutboundHttp::class, $fake);

    $run = runSitemap(sitemapContext(['speed' => 'fastest', 'path' => $target === 'document' ? '/custom.xml' : '/sitemap.xml']));

    expect($run->error)->toBeNull();
    expect($run->result)->toBe(RunResult::Failed)
        ->and($run->summary)->toBe($status === 404 ? ($target === 'document' ? 'sitemap.summary.not_found' : 'sitemap.summary.url_404') : 'sitemap.summary.issue')
        ->and($run->summary_params)->toBe($status === 404 && $target === 'document' ? ['path' => 'custom.xml'] : ['count' => 1]);
    $snapshot = json_decode(DB::table('sitemap_snapshots')->where('run_id', $run->id)->sole()->snapshot, true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['issue_counts'])->toBe(['http_'.$status => 1])
        ->and($snapshot['issues'])->toEqual([[
            'url' => 'http://fixtures.test/'.($target === 'document' ? 'custom.xml' : 'page'),
            'type' => 'sitemap.issue.unreachable', 'kind' => 'http_status', 'status' => $status,
        ]]);
    $type = app(CheckRegistry::class)->get(CheckKey::Sitemap);
    $input = new CheckRunPresentationInput($run->result, $run->evidence, $run->metrics, $run->summary, $run->summary_params);
    $presentation = app(CheckRunPresentations::class)->forRun(Check::findOrFail($run->check_id), $run);
    expect(CheckHeadline::format($presentation, $locale))->toBe($headline);
    expect(__($presentation->history_label->key, $presentation->history_label->params, $locale))->toBe($historyLabel);
    $mail = $type->mailEvidence($input, $run->ended_at, $locale, 'UTC', new MailEventFacts('check.result_changed', [], $run->check_id, null, null, $run->reference()));
    expect($mail[0]->rows[0][1]->text)->toBe((string) $status);
    $export = DB::transaction(fn (): array => [...app(SitemapPersistence::class)->exportFindings(CheckKey::Sitemap, $run->check_id, $run->reference())]);
    expect($export[0]['result'])->toBe($status.' · '.$problem);
    expect($fake->requests)->toHaveCount($target === 'document' ? 1 : 3);
})->with([
    [404, 'en', '1 sitemap URL returns 404.', 'HTTP 404', 'URL did not return HTTP 200.'],
    [404, 'de', '1 Sitemap-URL antwortet mit 404.', 'HTTP 404', 'URL hat nicht mit HTTP 200 geantwortet.'],
    [600, 'en', '1 sitemap URL returns HTTP 600.', 'HTTP 600', 'URL did not return HTTP 200.'],
    [600, 'de', '1 Sitemap-URL antwortet mit HTTP 600.', 'HTTP 600', 'URL hat nicht mit HTTP 200 geantwortet.'],
    [999, 'en', '1 sitemap URL returns HTTP 999.', 'HTTP 999', 'URL did not return HTTP 200.'],
    [999, 'de', '1 Sitemap-URL antwortet mit HTTP 999.', 'HTTP 999', 'URL hat nicht mit HTTP 200 geantwortet.'],
])->with(['page', 'document']);

it('keeps the fake HTTP seam rejecting out-of-range statuses without staging findings', function (int $status): void {
    fakeSitemap(sitemapXml(['http://fixtures.test/page']))
        ->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult($status, failure: new FetchFailure(FetchFailureKind::HttpStatus, $status)))
        ->respondWith(sitemapRequest('/page', operator: false), new FetchResult($status, failure: new FetchFailure(FetchFailureKind::HttpStatus, $status)));

    $run = runSitemap(sitemapContext(['speed' => 'fastest']));

    expect($run->result)->toBe(RunResult::Errored)
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'class' => InvalidArgumentException::class, 'message' => 'Status code must be an integer value between 1xx and 5xx.',
        ]);
    expect(DB::table('sitemap_snapshots')->count())->toBe(0);
})->with([99, 1000]);

it('rejects malformed HTTP facts in retained sitemap presentations', function (mixed $status, string $key, string $message): void {
    fakeSitemap(sitemapXml(['http://fixtures.test/page']))
        ->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)))
        ->respondWith(sitemapRequest('/page', operator: false), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    $run = runSitemap(sitemapContext(['speed' => 'fastest']));
    $evidence = $run->evidence;
    $evidence['issues'][0]['status'] = $status;
    $evidence['issue_counts'] = [$key => 1];

    expect(fn () => app(CheckRegistry::class)->get(CheckKey::Sitemap)->presentRun(new CheckRunPresentationInput(
        $run->result, $evidence, $run->metrics, $run->summary, $run->summary_params,
    )))->toThrow(LogicException::class, $message);
})->with([
    [99, 'http_99', 'Sitemap issue HTTP status is invalid.'],
    [1000, 'http_1000', 'Sitemap issue HTTP status is invalid.'],
    ['999', 'http_999', 'Sitemap issue HTTP status is invalid.'],
    [999.5, 'http_999', 'Sitemap issue HTTP status is invalid.'],
    [404, 'http_99', 'Sitemap reason counts are invalid.'],
    [404, 'http_1000', 'Sitemap reason counts are invalid.'],
    [404, 'http_099', 'Sitemap reason counts are invalid.'],
    [404, "http_999\n", 'Sitemap reason counts are invalid.'],
]);

it('bounds retained sitemap statuses at the persistence boundary', function (mixed $status, bool $valid, string $field): void {
    fakeSitemap(sitemapXml(['http://fixtures.test/page']))->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(200));
    $context = sitemapContext(['speed' => 'fastest']);
    $type = app(CheckRegistry::class)->get(CheckKey::Sitemap);
    $type->execute($context);
    $snapshot = json_decode(DB::table('sitemap_snapshots')->sole()->snapshot, true, flags: JSON_THROW_ON_ERROR);
    $snapshot['comparisonIssues'] = null;
    $snapshot['not_verifiable'] = [['url' => 'http://fixtures.test/page', 'reason' => 'bot_protection', 'status' => $status]];
    if ($field === 'issues') {
        $snapshot['not_verifiable'] = [];
        $snapshot['issues'] = [['url' => 'http://fixtures.test/page', 'type' => 'sitemap.issue.unreachable', 'kind' => 'http_status', 'status' => $status]];
        $snapshot['totalIssuesCount'] = 1;
        $snapshot['hasIssues'] = true;
        $snapshot['issue_counts'] = ['http_'.$status => 1];
    }

    if (! $valid) {
        try {
            $type->persistence()->stage($context, 'snapshot', [$snapshot]);
            $this->fail('Invalid sitemap status accepted.');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe([$field.'.0.status']);
        }

        return;
    }
    $type->persistence()->stage($context, 'snapshot', [$snapshot]);
    expect(json_decode(DB::table('sitemap_snapshots')->sole()->snapshot, true, flags: JSON_THROW_ON_ERROR)[$field][0]['status'])->toBe($status);
})->with([[600, true], [999, true], [99, false], [1000, false], ['999', false], [999.5, false]])->with(['issues', 'not_verifiable']);

it('records the default sitemap as not published when neither the path nor robots advertises one', function (): void {
    $fake = (new FakeOutboundHttp)
        ->respondWith(sitemapRequest(), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)))
        ->respondWith(sitemapRequest('/robots.txt'), new FetchResult(200, "User-agent: *\nDisallow:\n"));
    app()->instance(OutboundHttp::class, $fake);

    $run = runSitemap(sitemapContext());

    expect($run->result)->toBe(RunResult::Succeeded)
        ->and($run->summary)->toBe('sitemap.not_published')
        ->and($run->summary_params)->toBe([])
        ->and($run->metrics)->toBe(['hasIssues' => false, 'totalUrlCount' => 0, 'totalIssuesCount' => 0])
        ->and($fake->requests)->toHaveCount(2);
});

it('records discovered invalid URLs as issues but propagates header configuration failures', function (bool $headerFailure): void {
    $fake = fakeSitemap(sitemapXml(['http://fixtures.test/page']))->respondWith(sitemapRequest('/page', 'HEAD', false),
        new FetchResult(failure: new FetchFailure(FetchFailureKind::InvalidRequest, headerName: $headerFailure ? 'Host' : null, headerPosition: $headerFailure ? 0 : null)));
    $context = sitemapContext(['speed' => 'fastest']);

    $run = runSitemap($context);

    expect($run->result)->toBe($headerFailure ? RunResult::Errored : RunResult::Failed);
    if (! $headerFailure) {
        expect(app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'])->toEqual([
            ['url' => 'http://fixtures.test/page', 'type' => 'sitemap.issue.invalid_url', 'kind' => 'invalid_request', 'status' => null],
        ]);
    }
    expect($fake->requests)->toHaveCount(2);
})->with([false, true]);

it('caps reachability without presenting unchecked URLs as checked', function (): void {
    $pages = array_map(static fn (int $index): string => 'http://fixtures.test/page-'.$index, range(1, 102));
    $fake = fakeSitemap(sitemapXml($pages));
    foreach (array_slice($pages, 0, 100) as $page) {
        $fake->respondWith(sitemapRequest(substr($page, strlen('http://fixtures.test')), 'HEAD', false), new FetchResult(200));
    }
    config(['sitemap.max_urls' => 101]);
    acceptSitemap(sitemapContext(['speed' => 'fastest', 'urls_per_run' => '100']));
    $snapshot = json_decode(DB::table('sitemap_snapshots')->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['totalUrlCount'])->toBe(101);
    expect($snapshot['sitemaps'][0]['checkedReachabilityOfAllUrls'])->toBeFalse();
    expect($snapshot['issues'])->toEqual([['url' => 'http://fixtures.test/sitemap.xml', 'type' => 'sitemap.issue.url_limit']]);
    expect($fake->requests)->toHaveCount(101);
});

it('checks structure only without page requests and carries earlier page findings forward', function (): void {
    $fake = (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'http://fixtures.test/sitemap.xml' => new FetchResult(200, sitemapXml(['http://fixtures.test/child.xml', 'http://fixtures.test/missing.xml'], true)),
        'http://fixtures.test/child.xml' => new FetchResult(200, sitemapXml(['http://fixtures.test/broken', 'http://fixtures.test/fine'])),
        'http://fixtures.test/fine' => new FetchResult(200),
        default => new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)),
    });
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext(['speed' => 'fastest']);
    runSitemap($context);
    $incident = Incident::where('check_id', $context->checkId)->sole();
    $broken = ['kind' => 'http_status', 'status' => 404, 'type' => 'sitemap.issue.unreachable', 'url' => 'http://fixtures.test/broken'];
    $missing = ['kind' => 'http_status', 'status' => 404, 'type' => 'sitemap.issue.unreachable', 'url' => 'http://fixtures.test/missing.xml'];
    Check::findOrFail($context->checkId)->update(['config' => app(CheckRegistry::class)->get(CheckKey::Sitemap)->definition()->configSchema->validateConfig(['speed' => 'fastest', 'urls_per_run' => '0'])]);
    $fake->requests = [];

    $run = runSitemap($context);

    expect(array_map(static fn (array $entry): string => $entry['request']->method.' '.$entry['request']->url, $fake->requests))
        ->toBe(['GET http://fixtures.test/sitemap.xml', 'GET http://fixtures.test/child.xml', 'GET http://fixtures.test/missing.xml']);
    expect($run->result)->toBe(RunResult::Failed);
    $row = DB::table('sitemap_snapshots')->where('run_id', $run->id)->sole();
    expect(json_decode($row->snapshot, true)['issues'])->toEqual([$missing]);
    expect(DB::table('sitemap_findings')->where('snapshot_id', $row->id)->where('status', 'broken')->orderBy('url')->get(['url', 'carried_forward'])->map(fn (object $finding): array => (array) $finding)->all())
        ->toBe([['url' => 'http://fixtures.test/broken', 'carried_forward' => true], ['url' => 'http://fixtures.test/missing.xml', 'carried_forward' => false]]);
    expect(app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'])->toEqual([$broken, $missing]);
    expect(Event::where('type', 'sitemap.issues_changed')->count())->toBe(0);
    expect($incident->refresh()->resolved_at)->toBeNull();
});

/** @return list<string> */
function sitemapPages(int $count): array
{
    return array_map(static fn (int $index): string => 'http://fixtures.test/page-'.$index, range(1, $count));
}

/** @return list<string> */
function probedSitemapPages(FakeOutboundHttp $fake): array
{
    $probed = array_map(static fn (array $entry): string => $entry['request']->url, array_filter($fake->requests, static fn (array $entry): bool => $entry['request']->method === 'HEAD'));
    $fake->requests = [];

    return array_values($probed);
}

it('rotates the probe window through the discovered list and wraps around', function (): void {
    $fake = (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => $request->url === 'http://fixtures.test/sitemap.xml'
        ? new FetchResult(200, sitemapXml(sitemapPages(250))) : new FetchResult(200));
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext(['speed' => 'fastest', 'urls_per_run' => '100']);

    foreach ([[1, 100], [101, 200], [201, 250], [1, 100]] as [$first, $last]) {
        expect(runSitemap($context)->result)->toBe(RunResult::Succeeded);
        expect(probedSitemapPages($fake))->toBe(array_slice(sitemapPages(250), $first - 1, $last - $first + 1));
    }
});

it('keeps a broken URL open while other windows are probed and recovers only when it is re-probed OK', function (): void {
    $brokenPage = true;
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use (&$brokenPage): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            return new FetchResult(200, sitemapXml(sitemapPages(250)));
        }

        return $brokenPage && $request->url === 'http://fixtures.test/page-7' ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200);
    });
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext(['speed' => 'fastest', 'urls_per_run' => '100']);
    $store = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence();
    $broken = ['kind' => 'http_status', 'status' => 404, 'type' => 'sitemap.issue.unreachable', 'url' => 'http://fixtures.test/page-7'];

    expect(runSitemap($context)->result)->toBe(RunResult::Failed);
    expect(probedSitemapPages($fake))->toContain('http://fixtures.test/page-7');
    $incident = Incident::where('check_id', $context->checkId)->sole();
    $brokenPage = false;
    foreach ([2, 3] as $run) {
        expect(runSitemap($context)->result)->toBe(RunResult::Failed);
        expect(probedSitemapPages($fake))->not->toContain('http://fixtures.test/page-7');
        expect($store->baseline($context->checkId)['issues'])->toEqual([$broken]);
        expect($incident->refresh()->resolved_at)->toBeNull();
        expect(Event::where('type', 'sitemap.issues_changed')->count())->toBe(0);
    }

    expect(runSitemap($context)->result)->toBe(RunResult::Succeeded);
    expect(probedSitemapPages($fake))->toContain('http://fixtures.test/page-7');
    expect($store->baseline($context->checkId)['issues'])->toBe([]);
    expect($incident->refresh()->resolved_at)->not->toBeNull();
    expect(Event::where('type', 'sitemap.issues_changed')->sole()->payload['resolved'])->toEqual([$broken]);
});

it('restarts the rotation at the first URL when the sitemap shrinks below the cursor', function (): void {
    $count = 250;
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use (&$count): FetchResult {
        return $request->url === 'http://fixtures.test/sitemap.xml' ? new FetchResult(200, sitemapXml(sitemapPages($count))) : new FetchResult(200);
    });
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext(['speed' => 'fastest', 'urls_per_run' => '100']);
    runSitemap($context);
    runSitemap($context);
    $fake->requests = [];
    $count = 150;

    expect(runSitemap($context)->result)->toBe(RunResult::Succeeded);
    expect(probedSitemapPages($fake))->toBe(array_slice(sitemapPages(150), 0, 100));
    expect(runSitemap($context)->result)->toBe(RunResult::Succeeded);
    expect(probedSitemapPages($fake))->toBe(array_slice(sitemapPages(150), 100));
});

it('probes as many URLs as fit the remaining budget and keeps the cursor when a batch overruns the deadline', function (): void {
    $slow = 'http://fixtures.test/page-1';
    $expire = null;
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request, CheckContext $active) use (&$slow, &$expire): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            return new FetchResult(200, sitemapXml(sitemapPages(250)));
        }
        if ($request->url === $slow) {
            // A slow first batch leaves less than twice its duration plus the reserve.
            $this->travel(150)->seconds();
        }
        if ($request->url === $expire) {
            $this->travelTo($active->deadline);
        }

        return new FetchResult(200);
    });
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext(['speed' => 'fastest', 'urls_per_run' => 'max']);

    $planned = runSitemap($context);
    expect($planned->result)->toBe(RunResult::Succeeded);
    expect(probedSitemapPages($fake))->toBe(array_slice(sitemapPages(250), 0, 100));
    expect(json_decode(DB::table('sitemap_snapshots')->where('run_id', $planned->id)->value('snapshot'), true)['rotation_next'])->toBe(100);

    $slow = null;
    $expire = 'http://fixtures.test/page-150';
    $overrun = runSitemap($context);
    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
    expect($overrun->result)->toBe(RunResult::Errored)->and($overrun->summary)->toBe('sitemap.incomplete_time_limit_progress');
    expect(probedSitemapPages($fake)[0])->toBe('http://fixtures.test/page-101');

    $expire = null;
    expect(runSitemap($context)->result)->toBe(RunResult::Succeeded);
    // A window ends at the end of the list; the next run starts again at the first URL.
    expect(probedSitemapPages($fake))->toBe(array_slice(sitemapPages(250), 100));
    expect(runSitemap($context)->result)->toBe(RunResult::Succeeded);
    expect(probedSitemapPages($fake))->toBe(sitemapPages(250));
});

it('bounds unique documents and depth without fetching cyclic or over-budget references', function (int $maxSitemaps, int $maxDepth, string $issueUrl): void {
    $fake = fakeSitemap(sitemapXml(['http://fixtures.test/sitemap.xml', 'http://fixtures.test/child.xml'], true))
        ->respondWith(sitemapRequest('/child.xml', operator: false), new FetchResult(200, sitemapXml(['http://fixtures.test/grandchild.xml'], true)));
    config(['sitemap.max_sitemaps' => $maxSitemaps, 'sitemap.max_depth' => $maxDepth]);
    $context = sitemapContext(['speed' => 'fastest']);
    acceptSitemap($context);
    expect(app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'])->toEqual([['url' => $issueUrl, 'type' => 'sitemap.issue.recursion_limit']]);
    expect($fake->requests)->toHaveCount($maxSitemaps === 1 ? 1 : 2);
})->with([[1, 5, 'http://fixtures.test/sitemap.xml'], [100, 1, 'http://fixtures.test/grandchild.xml']]);

it('adds legacy delay to the fixed host spacing while preserving every speed enum', function (string $speed, int $milliseconds): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    fakeSitemap(sitemapXml(['http://fixtures.test/page']))->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(200));
    $context = sitemapContext(['speed' => $speed]);
    acceptSitemap($context);
    expect(Check::findOrFail($context->checkId)->executionConfig()['speed'])->toBe($speed);
    Sleep::assertSequence([Sleep::for(110 + $milliseconds)->milliseconds()]);
})->with([['default', 25], ['fast', 10], ['slow', 50], ['slowest', 100], ['fastest', 0]]);

it('publishes new and resolved issues once from retained accepted baselines at unchanged severity', function (): void {
    $fake = fakeSitemap(sitemapXml(['http://fixtures.test/a?page=2', 'http://fixtures.test/b']))
        ->respondWith(sitemapRequest('/a?page=2', 'HEAD', false), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)))
        ->respondWith(sitemapRequest('/a?page=2', operator: false), new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    $fake->respondWith(sitemapRequest('/b', 'HEAD', false), new FetchResult(200));
    $first = sitemapContext(['speed' => 'fastest']);
    acceptSitemap($first);
    expect(Event::query()->where('type', 'sitemap.issues_changed')->count())->toBe(0);
    $fake->respondWith(sitemapRequest(), new FetchResult(200, sitemapXml(['http://fixtures.test/a?page=2', 'http://fixtures.test/b'])))
        ->respondWith(sitemapRequest('/a?page=2', 'HEAD', false), new FetchResult(200))
        ->respondWith(sitemapRequest('/b', 'HEAD', false), new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500)))
        ->respondWith(sitemapRequest('/b', operator: false), new FetchResult(500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500)));
    acceptSitemap(sitemapContext(['speed' => 'fastest'], $first));
    $event = Event::query()->where('type', 'sitemap.issues_changed')->sole();
    expect($event->from_result)->toBe(CheckResult::Failed);
    expect($event->to_result)->toBe(CheckResult::Failed);
    expect(Arr::only($event->payload, ['new', 'resolved']))->toEqual([
        'new' => [['url' => 'http://fixtures.test/b', 'type' => 'sitemap.issue.unreachable', 'kind' => 'http_status', 'status' => 500]],
        'resolved' => [['url' => 'http://fixtures.test/a?page=2', 'type' => 'sitemap.issue.unreachable', 'kind' => 'http_status', 'status' => 404]],
    ]);
    app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->prune(CarbonImmutable::now()->addDays(61));
    expect(DB::table('sitemap_snapshots')->count())->toBe(1);
    acceptSitemap(sitemapContext(['speed' => 'fastest'], $first));
    expect(Event::query()->where('type', 'sitemap.issues_changed')->count())->toBe(1);
});

it('rejects credential-bearing URLs without exposing userinfo', function (): void {
    $fake = fakeSitemap(sitemapXml(['http://user:secret@fixtures.test/page#fragment']));
    acceptSitemap(sitemapContext(['speed' => 'fastest']));
    expect(DB::table('sitemap_snapshots')->value('snapshot'))->not->toContain('user:', 'secret', '#fragment');
    expect(CheckRun::query()->sole()->result)->toBe(RunResult::Failed);
    expect($fake->requests)->toHaveCount(1);
});

it('accepts a known 200 when GET fallback exceeds the body cap', function (): void {
    fakeSitemap(sitemapXml(['http://fixtures.test/page']))
        ->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(405, failure: new FetchFailure(FetchFailureKind::HttpStatus, 405)))
        ->respondWith(sitemapRequest('/page', operator: false), new FetchResult(200, failure: new FetchFailure(FetchFailureKind::TooLarge, 200)));
    expect(runSitemap(sitemapContext(['speed' => 'fastest']))->result)->toBe(RunResult::Succeeded);
});

it('fails loudly on unexpected seam requests without staging', function (): void {
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
    $context = sitemapContext();
    expect(fn () => app(CheckRegistry::class)->get(CheckKey::Sitemap)->execute($context))->toThrow(LogicException::class, 'No configured');
    expect(DB::table('sitemap_snapshots')->count())->toBe(0);
});

it('preserves an open failure and baseline while showing incomplete deadline evidence', function (): void {
    $context = sitemapContext(['speed' => 'fastest']);
    $mode = 'broken';
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request, CheckContext $active) use (&$mode): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            if ($mode === 'deadline') {
                $this->travelTo($active->deadline);
            }

            return new FetchResult(200, sitemapXml($mode === 'deadline' ? ['http://fixtures.test/one', 'http://fixtures.test/broken'] : ['http://fixtures.test/broken']));
        }

        return $mode === 'broken' ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200);
    });
    app()->instance(OutboundHttp::class, $fake);
    $first = runSitemap($context);
    $store = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence();
    $baseline = $store->baseline($context->checkId);
    expect($baseline['issues'])->toHaveCount(1);
    $mode = 'deadline';
    $partial = runSitemap($context);
    $this->travelBack();
    expect($partial->result)->toBe(RunResult::Errored)
        ->and($partial->summary)->toBe('sitemap.incomplete_time_limit_progress')
        ->and($partial->metrics)->toBeNull();
    expect(DB::table('events')->where('type', 'sitemap.issues_changed')->count())->toBe(0);
    expect($store->baseline($context->checkId))->toBe($baseline);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    config(['heptapod.features.ui_wiring' => true]);
    $this->actingAs($user)->get('/sites/'.$context->siteId.'/checks/'.$context->checkId)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('check.result', 'failed')->where('site.state', 'failed')
        ->where('detail.check_type', 'sitemap')->where('detail.issues.0.type', 'sitemap.issue.time_limit')->has('detail.sitemaps', 0));
    $incident = Incident::where('check_id', $context->checkId)->sole();
    expect($incident->resolved_at)->toBeNull();
    $this->get('/sites', ['X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sites'])->assertInertia(fn (Assert $page): Assert => $page
        ->where('sites.0.id', $context->siteId)->where('sites.0.state', 'failed')
        ->where('sites.0.checks.0.result', 'failed')->where('sites.0.checks.0.open_incident_id', $incident->id));
    $this->flushHeaders();
    $this->get('/sites/'.$context->siteId)->assertInertia(fn (Assert $page): Assert => $page
        ->where('site.state', 'failed')->where('site.checks.0.open_incident_id', $incident->id));
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->where('tiles.check_errors', 0)->where('tiles.failed', 1)->where('tiles.open_incidents', 1)->where('needs_attention_total', 1)
        ->where('needs_attention.0.id', $context->siteId)->where('needs_attention.0.state', 'failed')
        ->where('needs_attention.0.checks.0.open_incident_id', $incident->id));
    $this->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('incident.resolved_at', null)->where('incident.cause', 'failed')->where('site.state', 'failed')
        ->where('site.checks.0.result', 'failed')->where('site.checks.0.open_incident_id', $incident->id));
    expect($partial->evidence['sitemaps'])->toBe([]);
    expect(DB::table('sitemap_snapshots')->where('run_id', $partial->id)->exists())->toBeFalse();
    $store->prune(CarbonImmutable::now()->addDay());
    expect(DB::table('sitemap_snapshots')->orderBy('run_id')->pluck('run_id')->all())->toBe([$first->id]);
    $mode = 'healthy';
    runSitemap($context);
    $payload = json_decode(DB::table('events')->where('type', 'sitemap.issues_changed')->sole()->payload, true);
    expect($payload['new'])->toBe([]);
    expect($payload['resolved'])->toEqual($baseline['issues']);
    expect($incident->refresh()->resolved_at)->not->toBeNull();
    $this->get('/sites/'.$context->siteId)->assertInertia(fn (Assert $page): Assert => $page
        ->where('site.state', 'ok')->where('site.checks.0.open_incident_id', null));
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->where('tiles.check_errors', 0)->where('tiles.failed', 0)->where('tiles.open_incidents', 0)->where('needs_attention_total', 0));
});

it('withholds comparison when the final fetch exhausts the execution budget but not for ordinary request timeouts', function (string $target, bool $expires): void {
    $context = sitemapContext(['speed' => 'fastest']);
    $timingOut = false;
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request, CheckContext $active) use (&$timingOut, $target, $expires): FetchResult {
        $document = $request->url === 'http://fixtures.test/sitemap.xml';
        if ($timingOut && (($target === 'document' && $document) || ($target === 'page' && ! $document) || ($target === 'fallback' && ! $document && $request->method === 'GET'))) {
            if ($expires) {
                $this->travelTo($active->deadline);
            }

            return new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout));
        }
        if ($document) {
            return new FetchResult(200, sitemapXml(['http://fixtures.test/a']));
        }

        return new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404));
    }));
    runSitemap($context);
    $store = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence();
    $baseline = $store->baseline($context->checkId);
    $incident = Incident::where('check_id', $context->checkId)->sole();
    $events = Event::count();
    $timingOut = true;
    $run = runSitemap($context);
    $this->travelBack();
    $snapshot = $run->evidence;
    if ($expires) {
        expect($run->result)->toBe(RunResult::Errored)->and($run->summary)->toBe('sitemap.incomplete_time_limit_progress');
        expect($incident->refresh()->resolved_at)->toBeNull();
        expect(Event::count())->toBe($events);
        expect(DB::table('sitemap_snapshots')->where('run_id', $run->id)->exists())->toBeFalse();
        expect(array_column($snapshot['issues'], 'type'))->toContain('sitemap.issue.time_limit');
        expect($store->baseline($context->checkId))->toBe($baseline);
        expect(Event::where('type', 'sitemap.issues_changed')->count())->toBe(0);
        if ($target !== 'document') {
            expect($snapshot['sitemaps'][0]['checkedReachabilityOfAllUrls'])->toBeFalse();
        }
    } else {
        expect(DB::table('sitemap_snapshots')->where('run_id', $run->id)->value('comparison_issues'))->not->toBeNull();
        expect(array_column($snapshot['issues'], 'type'))->not->toContain('sitemap.issue.time_limit');
        expect($snapshot['issues'][0]['kind'])->toBe('timeout');
        expect($store->baseline($context->checkId)['run_id'])->toBe($run->id);
        expect(Event::where('type', 'sitemap.issues_changed')->count())->toBe(1);
    }
})->with([['document', true], ['page', true], ['fallback', true], ['document', false], ['page', false], ['fallback', false]]);

it('retains prior page findings through empty well-formed sitemap roots without a false new issue on return', function (string $empty): void {
    $context = sitemapContext(['speed' => 'fastest']);
    $body = sitemapXml(['http://fixtures.test/a']);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => $request->url === 'http://fixtures.test/sitemap.xml'
        ? new FetchResult(200, $body) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    runSitemap($context);
    $store = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence();
    $firstIssues = $store->baseline($context->checkId)['issues'];
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(200, $empty)));
    runSitemap($context);
    expect($store->baseline($context->checkId)['issues'])->toContain($firstIssues[0]);
    expect(Event::where('type', 'sitemap.issues_changed')->sole()->payload['resolved'])->toBe([]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => $request->url === 'http://fixtures.test/sitemap.xml'
        ? new FetchResult(200, $body) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    runSitemap($context);
    expect($store->baseline($context->checkId)['issues'])->toBe($firstIssues);
    expect(Event::where('type', 'sitemap.issues_changed')->latest('id')->firstOrFail()->payload['new'])->toBe([]);
})->with(['<urlset/>', '<sitemapindex/>']);

it('carries unchecked findings when a configured cap shifts the checked URL set', function (): void {
    $context = sitemapContext(['speed' => 'fastest', 'urls_per_run' => '100']);
    $mode = 'broken';
    $grown = array_map(static fn (int $index): string => 'http://fixtures.test/one-'.$index, range(1, 100));
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use (&$mode, $grown): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            return new FetchResult(200, sitemapXml(str_starts_with($mode, 'grown') ? [...$grown, 'http://fixtures.test/broken'] : ['http://fixtures.test/broken']));
        }

        return in_array($mode, ['broken', 'grown_failed'], true) ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200);
    });
    app()->instance(OutboundHttp::class, $fake);
    runSitemap($context);
    $store = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence();
    $baseline = $store->baseline($context->checkId);
    expect($baseline['issues'])->toHaveCount(1);
    $incident = Incident::where('check_id', $context->checkId)->sole();
    $events = Event::count();
    $mode = 'grown';
    $partial = runSitemap($context);
    expect($partial->result)->toBe(RunResult::Failed);
    expect($incident->refresh()->resolved_at)->toBeNull();
    expect(Event::count())->toBe($events);
    expect(DB::table('events')->where('type', 'sitemap.issues_changed')->count())->toBe(0);
    expect($store->baseline($context->checkId))->toBe(['run_id' => $partial->id, 'issues' => $baseline['issues']]);
    expect($partial->metrics['totalIssuesCount'])->toBe(0);
    expect(json_decode(DB::table('sitemap_snapshots')->where('run_id', $partial->id)->value('snapshot'), true)['issues'])->toBe([]);
    $mode = 'grown_failed';
    // The rotation window now covers only the still-broken URL, so the comparison is unchanged.
    runSitemap($context);
    expect(DB::table('events')->where('type', 'sitemap.issues_changed')->count())->toBe(0);
    runSitemap($context);
    $payload = json_decode(DB::table('events')->where('type', 'sitemap.issues_changed')->sole()->payload, true);
    expect(array_column($payload['new'], 'url'))->toEqualCanonicalizing($grown);
    expect($payload['resolved'])->toBe([]);
    $carried = $store->baseline($context->checkId)['issues'];
    expect($carried)->toHaveCount(101);
    $mode = 'healthy';
    runSitemap($context);
    $payload = json_decode(DB::table('events')->where('type', 'sitemap.issues_changed')->orderByDesc('id')->first()->payload, true);
    expect(DB::table('events')->where('type', 'sitemap.issues_changed')->count())->toBe(2);
    expect($payload['new'])->toBe([]);
    expect($payload['resolved'])->toEqual($baseline['issues']);
});

it('drops out-of-scope URLs silently and bounds rotating complete observations', function (): void {
    $pages = ['http://fixtures.test/a'];
    $healthy = false;
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use (&$pages, &$healthy): FetchResult {
        return $request->url === 'http://fixtures.test/sitemap.xml' ? new FetchResult(200, sitemapXml($pages))
            : ($healthy ? new FetchResult(200) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)));
    }));
    $context = sitemapContext(['speed' => 'fastest']);
    runSitemap($context);
    $pages = ['http://fixtures.test/b'];
    $healthy = true;
    runSitemap($context);
    $store = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence();
    expect(DB::table('events')->where('type', 'sitemap.issues_changed')->count())->toBe(0);
    expect($store->baseline($context->checkId)['issues'])->toBe([]);
    $healthy = false;
    foreach (['c', 'd', 'e'] as $page) {
        $pages = ['http://fixtures.test/'.$page];
        runSitemap($context);
        expect(array_column($store->baseline($context->checkId)['issues'], 'url'))->toBe($pages);
        $event = Event::query()->where('type', 'sitemap.issues_changed')->latest('id')->first();
        expect($event->payload['resolved'])->toBe([]);
        expect(array_column($event->payload['new'], 'url'))->toBe($pages);
    }
});

it('carries undiscovered URLs without dropping them when discovery is limited', function (): void {
    config(['sitemap.max_urls' => 2, 'sitemap.max_sitemaps' => 2]);
    $pages = ['http://fixtures.test/a'];
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => $request->url === 'http://fixtures.test/sitemap.xml'
        ? new FetchResult(200, sitemapXml($pages)) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    $context = sitemapContext(['speed' => 'fastest']);
    runSitemap($context);
    $pages = ['http://fixtures.test/b', 'http://fixtures.test/c', 'http://fixtures.test/d'];
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => $request->url === 'http://fixtures.test/sitemap.xml'
        ? new FetchResult(200, sitemapXml($pages)) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    runSitemap($context);
    $issues = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'];
    expect(array_column($issues, 'url'))->toContain('http://fixtures.test/a', 'http://fixtures.test/b');
    expect(Event::query()->where('type', 'sitemap.issues_changed')->sole()->payload['resolved'])->toBe([]);
});

it('freezes comparison at its ceiling without events or incidents and retains current evidence', function (): void {
    config(['sitemap.max_urls' => 1, 'sitemap.max_sitemaps' => 1]);
    $pages = ['http://fixtures.test/a', 'http://fixtures.test/spare'];
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request) use (&$pages): FetchResult {
        return $request->url === 'http://fixtures.test/sitemap.xml' ? new FetchResult(200, sitemapXml($pages))
            : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404));
    }));
    $context = sitemapContext(['speed' => 'fastest']);
    runSitemap($context);
    $store = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence();
    $baseline = $store->baseline($context->checkId);
    expect($baseline['issues'])->toHaveCount(2);
    $events = Event::query()->count();
    $incident = Incident::where('check_id', $context->checkId)->sole();
    $pages = ['http://fixtures.test/b', 'http://fixtures.test/spare'];
    $run = runSitemap($context);
    expect($run->result)->toBe(RunResult::Failed);
    expect($incident->refresh()->resolved_at)->toBeNull();
    expect(Event::query()->count())->toBe($events);
    expect($store->baseline($context->checkId))->toBe($baseline);
    $row = DB::table('sitemap_snapshots')->where('run_id', $run->id)->sole();
    expect($row->comparison_issues)->toBeNull();
    $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['notes'])->toBe(['sitemap.note.comparison_limit']);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    config(['heptapod.features.ui_wiring' => true]);
    $this->actingAs($user)->get('/sites/'.$context->siteId.'/checks/'.$context->checkId)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.notes.0', 'sitemap.note.comparison_limit')->where('detail.check_type', 'sitemap')
        ->where('detail.issues', fn (Collection $issues): bool => $issues->pluck('url')->contains('http://fixtures.test/b') && ! $issues->pluck('url')->contains('http://fixtures.test/a')));
    expect(json_encode($run->evidence, JSON_THROW_ON_ERROR))->not->toContain('comparisonIssues', 'comparison_issues');
    expect(array_column($snapshot['issues'], 'url'))->toContain('http://fixtures.test/b');
    expect(DB::table('sitemap_snapshots')->whereNotNull('comparison_issues')->get()->every(static fn (object $row): bool => count(json_decode($row->comparison_issues, true)) <= 2))->toBeTrue();
});

it('rejects oversized locations per entry without fetching or storing unbounded URLs', function (bool $index): void {
    $secret = 'ReflectedToken42';
    $long = 'http://fixtures.test/'.strtolower($secret).'/'.str_repeat('x', 50000);
    $context = sitemapContext(['speed' => 'fastest']);
    Site::findOrFail($context->siteId)->update(['crawler_headers' => [['name' => 'X-Crawler-Key', 'value' => $secret]]]);
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use ($long, $index): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            return new FetchResult(200, sitemapXml([$long, 'http://fixtures.test/normal'], $index));
        }

        return $request->method === 'HEAD' ? new FetchResult(200) : new FetchResult(200, sitemapXml(['http://fixtures.test/page']));
    });
    app()->instance(OutboundHttp::class, $fake);
    $run = runSitemap($context);
    expect(array_map(static fn (array $entry): string => $entry['request']->url, $fake->requests))->not->toContain($long);
    expect($fake->requests)->toHaveCount($index ? 3 : 2);
    expect($run->result)->toBe(RunResult::Failed);
    $row = DB::table('sitemap_snapshots')->where('run_id', $run->id)->sole();
    expect(strlen($row->snapshot) + strlen($row->comparison_issues))->toBeLessThan(12000);
    expect(strtolower($row->snapshot.$row->comparison_issues))->not->toContain(strtolower($secret));
    $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['issues'][0]['type'])->toBe('sitemap.issue.url_length');
    expect(strlen($snapshot['issues'][0]['url']))->toBeLessThanOrEqual(2124);
    expect($snapshot['issues'][0]['url'])->toContain('#sha256=');
})->with([false, true]);

it('bounds rejected index references by the URL processing limit', function (): void {
    config(['sitemap.max_urls' => 1, 'sitemap.max_sitemaps' => 1]);
    $urls = array_map(static fn (int $index): string => 'http://fixtures.test/'.str_repeat('x', 2048).$index, range(1, 3));
    $fake = fakeSitemap(sitemapXml($urls, true));
    $run = runSitemap(sitemapContext(['speed' => 'fastest']));
    $snapshot = json_decode(DB::table('sitemap_snapshots')->where('run_id', $run->id)->value('snapshot'), true);
    expect(array_column($snapshot['issues'], 'type'))->toEqualCanonicalizing(['sitemap.issue.url_length', 'sitemap.issue.url_limit']);
    expect($fake->requests)->toHaveCount(1);
});

it('preserves unknown page findings when child sitemap credentials prevent discovery', function (): void {
    $restricted = false;
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use (&$restricted): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            return new FetchResult(200, sitemapXml([$restricted ? 'http://user:password@fixtures.test/child' : 'http://fixtures.test/child'], true));
        }
        if ($request->url === 'http://fixtures.test/child') {
            return new FetchResult(200, sitemapXml(['http://fixtures.test/page']));
        }

        return new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404));
    });
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext(['speed' => 'fastest']);
    runSitemap($context);
    $restricted = true;
    $run = runSitemap($context);
    $comparison = json_decode(DB::table('sitemap_snapshots')->where('run_id', $run->id)->value('comparison_issues'), true);
    expect(array_column($comparison, 'url'))->toContain('http://fixtures.test/page');
    expect(Event::where('type', 'sitemap.issues_changed')->sole()->payload['resolved'])->toBe([]);
});

it('bounds current run evidence without replacing the stored observation', function (): void {
    $urls = array_map(static fn (int $index): string => 'http://fixtures.test/'.str_repeat('x', 2000).$index, range(1, 60));
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => $request->url === 'http://fixtures.test/sitemap.xml'
        ? new FetchResult(200, sitemapXml($urls)) : new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404))));
    $run = runSitemap(sitemapContext(['speed' => 'fastest']));
    expect($run->evidence['totalIssuesCount'])->toBe(60);
    expect($run->evidence['issues'])->toHaveCount(50);
    expect($run->evidence['sitemaps'][0]['issues'])->toHaveCount(5);
    expect(max(array_map(strlen(...), array_column($run->evidence['issues'], 'url'))))->toBeLessThanOrEqual(2048);
    expect(json_decode(DB::table('sitemap_snapshots')->where('run_id', $run->id)->value('snapshot'), true)['issues'])->toHaveCount(60);
});

it('preserves fenced staging, rollback, absent staging and idempotent publication', function (): void {
    fakeSitemap(sitemapXml(['http://fixtures.test/page']))->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(200));
    $type = app(CheckRegistry::class)->get(CheckKey::Sitemap);
    $context = sitemapContext();
    $other = new CheckContext($context->siteId, $context->checkId, $context->runId, (string) Str::uuid(), $context->url, $context->startedAt, $context->deadline, [], [], null);
    $run = new RunReference($context->runId, CarbonImmutable::parse('2026-10-01T12:00:00.123456Z'));
    $store = $type->persistence();
    $store->finalize($run, $context->claimToken);
    $store->discard($run->id, $context->claimToken);
    $type->execute($context);
    $type->execute($other);
    expect(fn () => DB::transaction(function () use ($store, $run, $context): void {
        $store->finalize($run, $context->claimToken);
        throw new RuntimeException('recorder rollback');
    }))->toThrow(RuntimeException::class, 'recorder rollback');
    expect(DB::table('sitemap_snapshots')->whereNotNull('run_ended_at')->count())->toBe(0);
    $store->finalize($run, $other->claimToken);
    $store->finalize($run, $other->claimToken);
    $store->discard($run->id, $context->claimToken);
    $store->discard($run->id, $context->claimToken);
    $store->discard($run->id, $other->claimToken);
    expect(DB::table('sitemap_snapshots')->sole()->claim_token)->toBe($other->claimToken);
    expect(DB::table('sitemap_snapshots')->value('run_ended_at'))->toContain('.123456');
    expect(fn () => $type->execute($other))->toThrow(LogicException::class, 'Cannot stage an accepted');
    expect(fn () => $store->stage($context, 'other', [[]]))->toThrow(InvalidArgumentException::class);
});

it('parses real bare and HTTP encoded gzip sitemaps with bounded file-format decoding', function (string $path, RunResult $result): void {
    // Native cURL compares its deadline to the wall clock.
    $this->travelBack();
    config(['sitemap.max_xml_bytes' => 512]);
    $context = sitemapContext(['path' => $path, 'speed' => 'fastest']);
    expect(runSitemap($context)->result)->toBe($result);
    if ($result === RunResult::Failed) {
        expect(app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'])->toEqual([
            ['url' => $context->url.$path, 'type' => 'sitemap.issue.size_limit'],
        ]);
    }
})->with([
    ['/sitemap/file.xml.gz', RunResult::Succeeded], ['/sitemap/encoded.xml.gz', RunResult::Succeeded], ['/sitemap/bomb.xml.gz', RunResult::Failed],
]);

it('blocks real private targets discovered in an otherwise trusted operator sitemap', function (): void {
    // Native cURL compares its deadline to the wall clock.
    $this->travelBack();
    $context = sitemapContext(['path' => '/sitemap/security.xml', 'speed' => 'fastest']);
    acceptSitemap($context);
    $issues = app(CheckRegistry::class)->get(CheckKey::Sitemap)->persistence()->baseline($context->checkId)['issues'];
    expect($issues)->toHaveCount(2);
    expect(array_column($issues, 'type'))->toBe(['sitemap.issue.blocked', 'sitemap.issue.blocked']);
    expect(array_column($issues, 'kind'))->each->toBeIn(['private_target', 'redirect_blocked']);
});

it('redacts reflected crawler secrets from accepted snapshots and new and resolved events', function (): void {
    $secret = 'ReflectedToken42';
    $reflected = strtolower($secret);
    $context = sitemapContext(['path' => '/sitemap.xml?token='.$reflected.'&keep=root-visible', 'speed' => 'fastest']);
    Site::findOrFail($context->siteId)->update(['crawler_headers' => [['name' => 'X-Crawler-Key', 'value' => $secret]]]);
    $observation = 0;
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request) use (&$observation, $reflected): FetchResult {
        if (str_contains($request->url, '/sitemap.xml?')) {
            $observation++;

            return new FetchResult(200, sitemapXml(['http://fixtures.test/child.xml?token='.$reflected.'&keep=child-visible'], true));
        }
        if (str_contains($request->url, '/child.xml?')) {
            return new FetchResult(200, sitemapXml(['http://fixtures.test/page-a?token='.$reflected.'&keep=page-visible', 'http://fixtures.test/page-b?token='.$reflected.'&keep=page-visible']));
        }

        return (($observation === 1 && str_contains($request->url, '/page-a?')) || ($observation === 2 && str_contains($request->url, '/page-b?')))
            ? new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)) : new FetchResult(200);
    });
    app()->instance(OutboundHttp::class, $fake);
    runSitemap($context);
    runSitemap($context);

    $snapshots = DB::table('sitemap_snapshots')->pluck('snapshot');
    $event = Event::query()->where('type', 'sitemap.issues_changed')->sole();
    expect(strtolower(json_encode($event->payload, JSON_THROW_ON_ERROR)))->not->toContain($reflected);
    expect(strtolower($snapshots->implode('')))->not->toContain($reflected);
    expect(strtolower(DB::table('sitemap_snapshots')->pluck('comparison_issues')->implode('')))->not->toContain($reflected);
    expect(array_keys($event->payload))->toEqualCanonicalizing(['new', 'resolved', 'run_id', 'run_ended_at', 'summary', 'summary_params']);
    $snapshot = json_decode($snapshots->last(), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot)->not->toHaveKeys(['comparisonIssues', 'comparison_issues']);
    expect($snapshot['checkUrl'])->toBe('http://fixtures.test/sitemap.xml?token=[redacted]&keep=root-visible');
    expect($snapshot['sitemapIndexes'][0]['url'])->toBe($snapshot['checkUrl']);
    expect($snapshot['sitemaps'][0]['url'])->toBe('http://fixtures.test/child.xml?token=[redacted]&keep=child-visible');
    expect($event->payload['new'][0]['url'])->toBe('http://fixtures.test/page-b?token=[redacted]&keep=page-visible');
    expect($event->payload['resolved'][0]['url'])->toBe('http://fixtures.test/page-a?token=[redacted]&keep=page-visible');
    expect($fake->requests[0]['request']->headers)->toBe([...sitemapPublicProfile(), 'Host' => ['fixtures.test'], 'x-crawler-key' => [$secret]]);
});

it('extracts declarationless CDATA and prefixed XML as sitemap URLs', function (string $body): void {
    $fake = fakeSitemap($body)->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(200));
    $context = sitemapContext(['speed' => 'fastest']);
    $run = runSitemap($context);

    expect($run->metrics['totalUrlCount'])->toBe(1);
    expect($run->result)->toBe(RunResult::Succeeded);
    expect($fake->requests)->toHaveCount(2);
})->with([
    'CDATA' => ['<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc><![CDATA[http://fixtures.test/page]]></loc></url></urlset>'],
    'no namespace' => ['<urlset><url><loc><![CDATA[http://fixtures.test/page]]></loc></url></urlset>'],
    'prefix' => ['<sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"><sm:url><sm:loc><![CDATA[http://fixtures.test/page]]></sm:loc></sm:url></sm:urlset>'],
]);

it('fails empty sitemaps instead of publishing an empty success', function (string $body): void {
    fakeSitemap($body);
    expect(runSitemap(sitemapContext())->result)->toBe(RunResult::Failed);
})->with(['<urlset/>', '<sitemapindex/>', '']);

it('records a check error without opening an incident when the deadline cuts off after a confirmed finding', function (): void {
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request, CheckContext $context): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            return new FetchResult(200, sitemapXml(['http://fixtures.test/broken', 'http://fixtures.test/pending']));
        }
        if ($request->method === 'HEAD') {
            return new FetchResult(405, failure: new FetchFailure(FetchFailureKind::HttpStatus, 405));
        }
        if ($request->url === 'http://fixtures.test/pending') {
            $this->travelTo($context->deadline);

            return new FetchResult(200);
        }

        return new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404));
    }));
    $run = runSitemap(sitemapContext(['speed' => 'fastest']));
    expect($run->result)->toBe(RunResult::Errored)
        ->and($run->summary)->toBe('sitemap.incomplete_time_limit_progress')
        ->and(array_column($run->evidence['issues'], 'type'))->toContain('sitemap.issue.unreachable', 'sitemap.issue.time_limit');
    expect(Incident::count())->toBe(0);
    expect(Event::count())->toBe(0);
});

it('stops sitemap traversal and pacing at the execution deadline with partial coverage', function (string $expiresAt): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request, CheckContext $context) use ($expiresAt): FetchResult {
        if ($request->url === 'http://fixtures.test/sitemap.xml') {
            if ($expiresAt === 'document') {
                $this->travelTo($context->deadline);
            }

            return new FetchResult(200, sitemapXml(['http://fixtures.test/one', 'http://fixtures.test/two', 'http://fixtures.test/three']));
        }
        $this->travelTo($context->deadline);

        return $expiresAt === 'fallback' ? new FetchResult(405, failure: new FetchFailure(FetchFailureKind::HttpStatus, 405)) : new FetchResult(200);
    });
    app()->instance(OutboundHttp::class, $fake);
    $context = sitemapContext();
    $run = runSitemap($context);
    $snapshot = $run->evidence;

    if ($expiresAt === 'document') {
        expect($snapshot['sitemaps'])->toBe([]);
    } else {
        expect($snapshot['sitemaps'][0]['checkedReachabilityOfAllUrls'])->toBeFalse();
    }
    expect($run->result)->toBe(RunResult::Errored)->and($run->summary)->toBe('sitemap.incomplete_time_limit_progress');
    expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'class' => ExternalTimeoutException::class,
        'message' => 'Sitemap execution time limit reached.',
    ]);
    expect(array_column($snapshot['issues'], 'type'))->toContain('sitemap.issue.time_limit');
    expect(Incident::count())->toBe(0);
    expect(Event::count())->toBe(0);
    expect(Check::findOrFail($context->checkId)->consecutive_failures)->toBe(0);
    expect($fake->requests)->toHaveCount($expiresAt === 'document' ? 1 : 2);
    Sleep::assertSequence($expiresAt === 'document' ? [] : [Sleep::for(135)->milliseconds()]);
})->with(['document', 'page', 'fallback']);

it('ignores sitemap extension locations while checking only the direct sitemap location', function (): void {
    $body = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1" xmlns:ext="https://extension.example/sitemap">'
        .'<url><loc>http://fixtures.test/page</loc><image:image><image:loc>http://fixtures.test/photo.jpg</image:loc></image:image>'
        .'<video:video><video:content_loc>http://fixtures.test/movie.mp4</video:content_loc></video:video>'
        .'<ext:loc>http://fixtures.test/extension</ext:loc><ext:url><ext:loc>http://fixtures.test/extra</ext:loc></ext:url></url></urlset>';
    $fake = fakeSitemap($body)->respondWith(sitemapRequest('/page', 'HEAD', false), new FetchResult(200));
    $run = runSitemap(sitemapContext(['speed' => 'fastest']));

    expect($run->result)->toBe(RunResult::Succeeded);
    expect($run->metrics['totalUrlCount'])->toBe(1);
    expect($fake->requests)->toHaveCount(2);
});

it('rejects invalid path, speed and URLs per run at the configuration boundary', function (array $config): void {
    expect(fn () => app(CheckRegistry::class)->get(CheckKey::Sitemap)->definition()->configSchema->validateConfig($config))->toThrow(ValidationException::class);
})->with([[['path' => '//external.fixtures.test/sitemap.xml']], [['path' => 'http://external.fixtures.test/sitemap.xml']], [['speed' => 'turbo']],
    [['urls_per_run' => '250']], [['urls_per_run' => 100]], [['urls_per_run' => 'all']]]);

it('accepts every closed URLs-per-run choice and defaults to 500', function (): void {
    $schema = app(CheckRegistry::class)->get(CheckKey::Sitemap)->definition()->configSchema;
    foreach (['0', '100', '500', '1000', 'max'] as $choice) {
        expect($schema->validateConfig(['urls_per_run' => $choice])->public)->toBe(['urls_per_run' => $choice]);
    }
    expect($schema->fields['urls_per_run']->default)->toBe('500');
});

it('renders historical incomplete summaries without parameters and counted summaries including zero checked', function (string $locale, string $historical, string $zero, string $partial): void {
    app()->setLocale($locale);

    expect(__('sitemap.incomplete_time_limit'))->toBe($historical)
        ->and(__('sitemap.incomplete_time_limit_progress', ['checked' => 0, 'total' => 20]))->toBe($zero)
        ->and(__('sitemap.incomplete_time_limit_progress', ['checked' => 8, 'total' => 20]))->toBe($partial);
})->with([
    ['en', 'Sitemap check incomplete (time limit reached).', 'Sitemap check incomplete (time limit reached; 0/20 discovered URLs checked).', 'Sitemap check incomplete (time limit reached; 8/20 discovered URLs checked).'],
    ['de', 'Sitemap-Prüfung unvollständig (Zeitlimit erreicht).', 'Sitemap-Prüfung unvollständig (Zeitlimit erreicht; 0/20 gefundene URLs geprüft).', 'Sitemap-Prüfung unvollständig (Zeitlimit erreicht; 8/20 gefundene URLs geprüft).'],
]);

it('names the classification of every exported sitemap row', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Sitemap]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'sitemap.failed',
        'started_at' => CarbonImmutable::parse('2026-10-28T11:00:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T11:02:00Z')]);
    DB::table('sitemap_snapshots')->insert(['site_id' => $check->site_id, 'check_id' => $check->id, 'run_id' => $run->id, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => $run->ended_at, 'created_at' => $run->ended_at, 'snapshot' => json_encode(['issues' => [
            ['url' => 'https://export.example.test/a', 'type' => 'causes.http.not_found', 'kind' => 'http_status', 'status' => 404],
            ['url' => 'https://export.example.test/sitemap.xml', 'type' => 'sitemap.issue.time_limit'],
        ], 'not_verifiable' => [['url' => 'https://export.example.test/c', 'reason' => 'bot_protection', 'status' => 403]]], JSON_THROW_ON_ERROR)]);

    $rows = DB::transaction(fn (): array => [...app(SitemapPersistence::class)->exportFindings(CheckKey::Sitemap, $check->id, $run->reference())]);
    expect(array_map(static fn (array $row): array => [$row['url'], $row['status']->value], $rows))->toBe([
        ['https://export.example.test/a', 'broken'], ['https://export.example.test/sitemap.xml', 'coverage_limit'], ['https://export.example.test/c', 'not_verifiable'],
    ]);
});

it('explains each XML rejection through accepted runs in English and German', function (string $body, string $reason, int $line, ?string $element, string $en, string $de): void {
    $fake = fakeSitemap($body);

    $run = runSitemap(sitemapContext());

    expect($run->result)->toBe(RunResult::Failed)
        ->and($run->summary)->toBe('sitemap.summary.invalid_xml')
        ->and($run->summary_params['xml_reason'])->toBe($reason)
        ->and($run->summary_params['xml_line'])->toBe($line);
    $snapshot = json_decode(DB::table('sitemap_snapshots')->where('run_id', $run->id)->sole()->snapshot, true, flags: JSON_THROW_ON_ERROR);
    expect(json_encode([$run->evidence, $run->summary_params, $snapshot], JSON_THROW_ON_ERROR))->not->toContain('username', 'password', 'private-token');
    expect($snapshot['issue_counts'])->toBe(['invalid' => 1])
        ->and($snapshot['issues'][0]['xml_reason'])->toBe($reason)
        ->and($snapshot['issues'][0]['xml_element'] ?? null)->toBe($element);
    $type = app(CheckRegistry::class)->get(CheckKey::Sitemap);
    $input = new CheckRunPresentationInput($run->result, $run->evidence, $run->metrics, $run->summary, $run->summary_params);
    $presentation = app(CheckRunPresentations::class)->forRun(Check::findOrFail($run->check_id), $run);
    foreach (['en' => $en, 'de' => $de] as $locale => $expected) {
        app()->setLocale($locale);
        expect(CheckHeadline::format($presentation, $locale))->toBe($expected);
        expect(__($presentation->history_label->key, $presentation->history_label->params, $locale))->toBe($expected);
        $mail = $type->mailEvidence($input, $run->ended_at, $locale, 'UTC', new MailEventFacts('check.result_changed', [], $run->check_id, null, null, $run->reference()));
        expect($mail[0]->rows[0][1]->text)->toBe($expected);
        $export = DB::transaction(fn (): array => [...app(SitemapPersistence::class)->exportFindings(CheckKey::Sitemap, $run->check_id, $run->reference())]);
        expect($export[0]['result'])->toBe($expected);
        $api = ApiHeadline::project($presentation, 'generic', CheckKey::Sitemap, false);
        expect($api['headline_parts']['reasons'][0]['key'])->toBe('sitemap.xml.'.$reason)
            ->and((array) $api['headline_parts']['reasons'][0]['params'])->toHaveKey('xml_line', $line);
    }
    expect($fake->requests)->toHaveCount(1);
})->with([
    'empty' => ['', 'empty_body', 1, null, 'The sitemap response is empty.', 'Die Sitemap-Antwort ist leer.'],
    'blank' => [" \n ", 'empty_body', 1, null, 'The sitemap response is empty.', 'Die Sitemap-Antwort ist leer.'],
    'bot protection HTML' => ['<!DOCTYPE html><html><title>Just a moment...</title><div id="cf-chl">Challenge</div></html>', 'html', 1, null, 'The server returned an HTML page instead of the sitemap (bot protection or an error page?).', 'Der Server hat statt der Sitemap eine HTML-Seite geliefert (Bot-Schutz oder eine Fehlerseite?).'],
    'HTML after an XML declaration and a comment' => ["<?xml version=\"1.0\"?>\n<!-- cache: miss -->\n<!DOCTYPE html><html><body>Error</body></html>", 'html', 1, null, 'The server returned an HTML page instead of the sitemap (bot protection or an error page?).', 'Der Server hat statt der Sitemap eine HTML-Seite geliefert (Bot-Schutz oder eine Fehlerseite?).'],
    'HTML without DOCTYPE' => ['<html><body>Error', 'html', 1, null, 'The server returned an HTML page instead of the sitemap (bot protection or an error page?).', 'Der Server hat statt der Sitemap eine HTML-Seite geliefert (Bot-Schutz oder eine Fehlerseite?).'],
    'not XML' => ['not xml', 'syntax', 1, null, 'The sitemap is not valid XML: syntax error on line 1.', 'Die Sitemap ist kein gültiges XML: Syntaxfehler in Zeile 1.'],
    'unclosed tag' => ["<urlset>\n<url>", 'unclosed_tag', 2, null, 'The sitemap is not valid XML: unclosed tag on line 2.', 'Die Sitemap ist kein gültiges XML: nicht geschlossenes Tag in Zeile 2.'],
    'mismatched tags' => ["<urlset>\n<url></urlset>", 'mismatched_tag', 2, null, 'The sitemap is not valid XML: mismatched tags on line 2.', 'Die Sitemap ist kein gültiges XML: nicht übereinstimmende Tags in Zeile 2.'],
    'Latin-1 byte in a UTF-8 document' => ["<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"><url><loc>https://fixtures.test/caf\xE9</loc></url></urlset>", 'invalid_encoding', 2, null, 'The sitemap is not valid XML: invalid encoding on line 2.', 'Die Sitemap ist kein gültiges XML: ungültige Zeichenkodierung in Zeile 2.'],
    'encoding' => ['<?xml version="1.0" encoding="unknown"?><urlset/>', 'invalid_encoding', 1, null, 'The sitemap is not valid XML: invalid encoding on line 1.', 'Die Sitemap ist kein gültiges XML: ungültige Zeichenkodierung in Zeile 1.'],
    'DOCTYPE' => ['<!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><urlset><url><loc>&xxe;</loc></url></urlset>', 'doctype', 1, null, 'The sitemap contains a forbidden DOCTYPE declaration.', 'Die Sitemap enthält eine unzulässige DOCTYPE-Deklaration.'],
    'wrong root' => ['<feed/>', 'root', 1, 'feed', 'The sitemap has the wrong root element: feed (line 1).', 'Die Sitemap hat ein falsches Wurzelelement: feed (Zeile 1).'],
    'bounded root' => ['<'.str_repeat('a', 60).'/>', 'root', 1, str_repeat('a', 40), 'The sitemap has the wrong root element: '.str_repeat('a', 40).' (line 1).', 'Die Sitemap hat ein falsches Wurzelelement: '.str_repeat('a', 40).' (Zeile 1).'],
    'namespace' => ['<urlset xmlns="https://wrong.example"/>', 'namespace', 1, null, 'The sitemap root has the wrong XML namespace (line 1).', 'Das Wurzelelement der Sitemap hat einen falschen XML-Namensraum (Zeile 1).'],
    'child' => ["<urlset>\n<image/></urlset>", 'child', 2, 'image', 'The sitemap contains an unexpected element: image (line 2).', 'Die Sitemap enthält ein unerwartetes Element: image (Zeile 2).'],
    'missing loc' => ["<urlset>\n<url/></urlset>", 'missing_loc', 2, null, 'A sitemap entry is missing a direct <loc> element (line 2).', 'Einem Sitemap-Eintrag fehlt ein direktes <loc>-Element (Zeile 2).'],
    'nested loc' => ['<urlset><url><x><loc>https://example.test/</loc></x></url></urlset>', 'missing_loc', 1, null, 'A sitemap entry is missing a direct <loc> element (line 1).', 'Einem Sitemap-Eintrag fehlt ein direktes <loc>-Element (Zeile 1).'],
    'duplicate loc' => ['<urlset><url><loc>https://example.test/a</loc><loc>https://example.test/b</loc></url></urlset>', 'duplicate_loc', 1, null, 'A sitemap entry contains more than one <loc> element (line 1).', 'Ein Sitemap-Eintrag enthält mehr als ein <loc>-Element (Zeile 1).'],
    'invalid loc with credentials' => ["<urlset><url>\n<loc>ftp://username:password@files.example.test/private?token=private-token</loc></url></urlset>", 'invalid_loc', 2, null, 'A sitemap <loc> is not a valid HTTP(S) URL (line 2).', 'Ein <loc>-Element der Sitemap enthält keine gültige HTTP(S)-URL (Zeile 2).'],
    'no entries' => ['<urlset/>', 'no_entries', 1, null, 'The sitemap contains no URL entries.', 'Die Sitemap enthält keine URL-Einträge.'],
    'gzip truncated' => [substr(gzencode('<urlset/>'), 0, -4), 'gzip', 1, null, 'The sitemap gzip file could not be decompressed or is truncated.', 'Die Gzip-Datei der Sitemap konnte nicht entpackt werden oder ist abgeschnitten.'],
    'gzip corrupt' => ["\x1f\x8bnot-gzip", 'gzip', 1, null, 'The sitemap gzip file could not be decompressed or is truncated.', 'Die Gzip-Datei der Sitemap konnte nicht entpackt werden oder ist abgeschnitten.'],
]);

it('keeps the same open XML finding and emits no change event when only its reason changes', function (): void {
    fakeSitemap('<urlset><url/> </urlset>');
    $context = sitemapContext();
    $first = runSitemap($context);
    $eventsBefore = Event::query()->where('check_id', $context->checkId)->count();
    $incident = Incident::query()->where('check_id', $context->checkId)->sole();
    $firstFinding = DB::table('sitemap_findings')->where('status', 'broken')->sole();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:01:00Z'));
    fakeSitemap('<html><body>Error</body></html>');

    $second = runSitemap($context);

    expect($first->summary_params['xml_reason'])->toBe('missing_loc')
        ->and($second->summary_params['xml_reason'])->toBe('html');
    expect(Incident::query()->where('check_id', $context->checkId)->whereNull('resolved_at')->sole()->id)->toBe($incident->id);
    expect(Event::query()->where('check_id', $context->checkId)->count())->toBe($eventsBefore);
    $secondSnapshot = DB::table('sitemap_snapshots')->where('run_id', $second->id)->sole();
    $secondFindings = DB::table('sitemap_findings')->where('snapshot_id', $secondSnapshot->id)->where('status', 'broken')->get();
    expect($secondFindings)->toHaveCount(1)
        ->and($secondFindings[0]->identity_hash)->toBe($firstFinding->identity_hash)
        ->and($secondFindings[0]->identity)->not->toContain('xml_reason');
    expect(json_encode($second->evidence, JSON_THROW_ON_ERROR))->not->toContain('username', 'password', 'private-token');
});

it('redacts reflected crawler secrets in XML element hints before staging', function (): void {
    $secret = 'PrivateCrawlerToken42';
    $context = sitemapContext();
    Site::findOrFail($context->siteId)->update(['crawler_headers' => [['name' => 'X-Crawler-Key', 'value' => $secret]]]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(200, '<'.$secret.'/>')));

    $run = runSitemap($context);

    expect($run->summary_params['xml_reason'])->toBe('root');
    $snapshot = DB::table('sitemap_snapshots')->where('run_id', $run->id)->sole();
    expect($snapshot->snapshot)->not->toContain($secret, strtolower($secret));
    expect(json_encode([$run->evidence, $run->summary_params], JSON_THROW_ON_ERROR))->not->toContain($secret, strtolower($secret));
});
