<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Crawl\CrawlArtifact;
use Heptapod\Addons\Crawl\CrawlEvidence;
use Heptapod\Addons\Crawl\CrawlPersistence;
use Heptapod\Addons\Crawl\LinkClassificationPolicy;
use Heptapod\Checks\CheckFreshness;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\SameSite;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Uri\WhatWg\Url;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
    // Public-suffix fixtures retain their real host spelling, but even automatic icon discovery stays offline.
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
    $prefix = 'crawl-evaluation-test:'.Str::uuid().':';
    config(['database.redis.options.prefix' => $prefix, 'cache.prefix' => $prefix]);
    Redis::purge('default');
    Redis::purge('cache');
    Cache::purge('redis');
});

function recordHeadlineCrawl(Check $check): CheckRun
{
    // The collector also reads native wall time; presentation fixtures must not expire when the frozen date passes.
    $claimed = ClaimedRun::start($check, headers: $check->site->crawler_headers ?? []);
    $context = new CheckContext($claimed->siteId, $claimed->checkId, $claimed->runId, $claimed->claimToken, $claimed->url,
        $claimed->startedAt, CarbonImmutable::parse('2100-01-01T00:00:00Z'), $claimed->config, $claimed->crawlerHeaders, $claimed->previousResult);
    $outcome = app(CheckRegistry::class)->get($check->type)->execute($context);
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken,
        $context->startedAt, $context->startedAt->addSecond(), $outcome))->accepted)->toBeTrue();

    return CheckRun::query()->where('check_id', $check->id)->orderByDesc('ended_at')->orderByDesc('id')->firstOrFail();
}

it('exposes rate-limited counts and collection reuse without hiding higher priority limits', function (CheckKey $type, ?string $limit, string $suffix, int $count = 2): void {
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $site = Site::factory()->create(['url' => 'https://crawl.example.test/']);
    $settings = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $check = $type === CheckKey::BrokenLinks ? $settings : Check::factory()->for($site)->create(['type' => $type]);
    $fake = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request, CheckContext $context): FetchResult => match ($request->url) {
        'https://crawl.example.test/' => new FetchResult(200, '<a href="/limited">Limited</a><a href="/limited">Repeated</a>'.($count === 2 ? '<a href="/limited-two">Also limited</a>' : ''), ['Content-Type' => ['text/html']]),
        'https://crawl.example.test/limited', 'https://crawl.example.test/limited-two' => new FetchResult(429, headers: ['Content-Type' => ['text/html']]),
        default => throw new LogicException('Unexpected crawler request: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $fake);
    $fresh = recordHeadlineCrawl($check);
    expect($fake->requests)->not->toBeEmpty();
    expect(array_column($fresh->evidence['notes'], 'key'))->not->toContain('crawl.collection_reused');
    $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    if ($limit !== null) {
        $artifact[$limit] = true;
    }
    DB::table('crawl_collections')->where('site_id', $site->id)->update(['artifact' => json_encode($artifact, JSON_THROW_ON_ERROR)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-21T13:00:00Z'));
    $reuseHttp = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $reuseHttp);

    $reused = recordHeadlineCrawl($check);

    expect($reuseHttp->requests)->toBeEmpty();
    expect($reused->summary)->toBe($type->value.'.'.$suffix);
    if ($limit === null) {
        expect($reused->summary_params)->toBe(['count' => $count]);
    }
    $presentationKey = $type === CheckKey::BrokenLinks && $limit !== null
        ? 'broken_links.presentation.'.($limit === 'time_limit_reached' ? 'time_limit' : 'page_limit')
        : $type->value.'.'.$suffix;
    $reuseNote = static fn (array|Collection $details): bool => collect($details)->contains(static fn (array $detail): bool => $detail['key'] === 'crawl.collection_reused' && $detail['params']['date'] === '2026-10-21T12:00:00+00:00');
    $this->actingAs($owner)->get('/sites/'.$site->id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('check.presentation.parts.0.key', $presentationKey)
        ->where('check.presentation.details', $reuseNote)
        ->where('runs.0.presentation.details', $reuseNote)
        ->where('runs.1.presentation.details', static fn (Collection $details): bool => ! $details->contains('key', 'crawl.collection_reused')));
    $attempt = CheckRunAttempt::query()->where('check_run_id', $reused->id)->sole();
    $response = $this->withToken($owner->createToken('crawl-read', ['checks:read'])->plainTextToken)
        ->getJson('/api/runs/attempt:'.$attempt->id)
        ->assertJsonPath('result.headline_parts.reasons.0.key', $presentationKey)
        ->assertJsonPath('result.headline_parts.details', $reuseNote);
    if ($limit === null) {
        $response->assertJsonPath('result.headline_parts.reasons.0.params.count', $count)
            ->assertJsonPath('result.headline', ($type === CheckKey::BrokenLinks ? 'Broken links' : 'Mixed content').': Crawl incomplete: rate limiting blocked '.$count.($count === 1 ? ' URL.' : ' URLs.'));
    }
})->with([
    'single rate-limited URL' => [CheckKey::BrokenLinks, null, 'incomplete_rate_limited_urls', 1],
    'broken links rate limit' => [CheckKey::BrokenLinks, null, 'incomplete_rate_limited_urls'],
    'mixed content rate limit' => [CheckKey::MixedContent, null, 'incomplete_rate_limited_urls'],
    'broken links time limit first' => [CheckKey::BrokenLinks, 'time_limit_reached', 'incomplete_time_limit'],
    'mixed content time limit first' => [CheckKey::MixedContent, 'time_limit_reached', 'incomplete_time_limit'],
    'broken links page cap first' => [CheckKey::BrokenLinks, 'page_cap_reached', 'incomplete'],
    'mixed content resource cap first' => [CheckKey::MixedContent, 'resource_cap_reached', 'incomplete_resources'],
]);

it('keeps confirmed failures and change facts beside rate limiting', function (CheckKey $type, bool $timeLimit = false): void {
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $site = Site::factory()->create(['url' => 'https://crawl.example.test/']);
    $settings = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link', 'image']])]);
    $check = $type === CheckKey::BrokenLinks ? $settings : Check::factory()->for($site)->create(['type' => $type]);
    $failures = false;
    $fake = new FakeOutboundHttp()->respondUsing(static function (FetchRequest $request, CheckContext $context) use (&$failures): FetchResult {
        return match ($request->url) {
            'https://crawl.example.test/' => new FetchResult(200, $failures ? '<a href="/missing">Missing</a><a href="/limited">Limited</a><img src="http://crawl.example.test/image.png">' : '<p>Baseline</p>', ['Content-Type' => ['text/html']]),
            'https://crawl.example.test/missing' => new FetchResult(404, headers: ['Content-Type' => ['text/html']]),
            'https://crawl.example.test/limited' => new FetchResult(429, headers: ['Content-Type' => ['text/html']]),
            'http://crawl.example.test/image.png' => new FetchResult(200, headers: ['Content-Type' => ['image/png']]),
            default => throw new LogicException('Unexpected crawler request: '.$request->url),
        };
    });
    app()->instance(OutboundHttp::class, $fake);
    recordHeadlineCrawl($check);
    DB::table('crawl_collections')->where('site_id', $site->id)->delete();
    $failures = true;
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:30:00Z'));

    $run = recordHeadlineCrawl($check);
    if ($timeLimit) {
        $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
        $artifact['time_limit_reached'] = true;
        DB::table('crawl_collections')->where('site_id', $site->id)->update(['artifact' => json_encode($artifact, JSON_THROW_ON_ERROR)]);
        $this->travelTo(CarbonImmutable::parse('2026-10-21T13:00:00Z'));
        $run = recordHeadlineCrawl($check);
    }

    expect($fake->requests)->not->toBeEmpty();
    expect($run->result)->toBe(RunResult::Failed);
    expect($run->summary)->toBe($type->value.'.'.($timeLimit ? 'incomplete_time_limit' : 'incomplete_rate_limited_urls'));
    $failureKey = $type === CheckKey::BrokenLinks ? 'broken_links.presentation.internal' : 'mixed_content.presentation.resources';
    $this->actingAs($owner)->get('/sites/'.$site->id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('check.presentation.parts.0.key', $failureKey)
        ->where('check.presentation.parts.0.params.count', 1)
        ->where('check.presentation.parts', static fn (Collection $parts): bool => $timeLimit
            ? $parts->contains('key', 'mixed_content.presentation.not_verifiable')
            : $parts->contains('key', $type->value.'.presentation.rate_limited')));
    $attempt = CheckRunAttempt::query()->where('check_run_id', $run->id)->sole();
    $response = $this->withToken($owner->createToken('combined-crawl-read', ['checks:read'])->plainTextToken)
        ->getJson('/api/runs/attempt:'.$attempt->id)
        ->assertJsonPath('result.headline_parts.reasons.0.key', $failureKey)
        ->assertJsonPath('result.headline_parts.reasons.0.params.count', 1);
    if (! $timeLimit && $type === CheckKey::BrokenLinks) {
        $response->assertJsonPath('result.headline', '1 broken internal link, rate limiting blocked 1 URL.');
    }
    if ($type === CheckKey::BrokenLinks) {
        $response->assertJsonPath('result.headline_parts.changes.0.key', 'broken_links.presentation.new')
            ->assertJsonPath('result.headline_parts.changes.0.params.count', 1)
            ->assertJsonPath('result.headline_parts.changes.0.params.total', 1)
            ->assertJsonPath('result.headline_parts.details.0.key', 'broken_links.presentation.not_verifiable');
    } else {
        $response->assertJsonPath('result.headline_parts.reasons.1.key', 'mixed_content.presentation.not_verifiable');
    }
})->with([
    'broken links with a new 404 and a 429' => [CheckKey::BrokenLinks],
    'mixed content with an insecure resource and a 429' => [CheckKey::MixedContent],
    'mixed content keeps findings when time limit takes precedence' => [CheckKey::MixedContent, true],
]);

it('renders the sites page for a retained crawl observation without crawl metrics', function (): void {
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $check = Check::factory()->create(['type' => CheckKey::MixedContent, 'latest_run_result' => CheckResult::Errored]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Errored, 'summary' => 'checks.engine.errored',
        'metrics' => [], 'evidence' => new CrawlEvidence([], false, 0, true, false, 2000, 0, 0, 0)->toArray(),
        'error' => json_encode(['class' => RuntimeException::class, 'message' => 'Retained check error'], JSON_THROW_ON_ERROR)]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);

    $this->actingAs($owner)->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->component('sites/index'));
});

it('keeps only reuse provenance beside the not-crawlable summary', function (): void {
    $site = Site::factory()->create(['url' => 'https://crawl.example.test/']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $fake = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request, CheckContext $context): FetchResult => $request->url === $site->url
        ? new FetchResult(429, headers: ['Content-Type' => ['text/html']])
        : throw new LogicException('Unexpected crawler request: '.$request->url));
    app()->instance(OutboundHttp::class, $fake);
    recordHeadlineCrawl($check);
    $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    $artifact['notes'][] = ['key' => 'crawl.coverage.depth_limit', 'params' => ['count' => 1, 'depth' => 5], 'labels' => []];
    DB::table('crawl_collections')->where('site_id', $site->id)->update(['artifact' => json_encode($artifact, JSON_THROW_ON_ERROR)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-21T13:00:00Z'));

    $run = recordHeadlineCrawl($check);

    expect($fake->requests)->toHaveCount(1);
    expect($run->summary)->toBe('crawl.not_crawlable.rate_limited.not_checked');
    expect(array_column($run->evidence['notes'], 'key'))->toBe(['crawl.collection_reused', 'crawl.not_crawlable.rate_limited.not_checked']);
});

it('retains mixed-content findings and freshness when the cached crawl did not scan the start page', function (bool $down): void {
    $site = Site::factory()->create(['url' => 'https://crawl.example.test/']);
    $uptime = Check::factory()->for($site)->create(['next_run_at' => now()->addDay()]);
    Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    $fake = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request, CheckContext $context): FetchResult => $request->url === $site->url
        ? new FetchResult(200, '<img src="http://crawl.example.test/insecure.png">', ['Content-Type' => ['text/html']])
        : new FetchResult(200, headers: ['Content-Type' => ['image/png']]));
    app()->instance(OutboundHttp::class, $fake);
    $previous = recordHeadlineCrawl($check);
    $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    $artifact['observed_pages'] = [];
    DB::table('crawl_collections')->where('site_id', $site->id)->update(['artifact' => json_encode($artifact, JSON_THROW_ON_ERROR)]);
    if ($down) {
        Incident::factory()->for($uptime)->create();
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-21T13:00:00Z'));
    $check->refresh()->update(['awaiting_result_since' => now()->subHour()]);
    $context = ClaimedRun::start($check);

    $outcome = app(CheckRegistry::class)->get(CheckKey::MixedContent)->execute($context);
    expect($outcome)->toBe(NoResultReason::MixedContentStartPageNotScanned);
    app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));

    expect($check->refresh()->awaiting_result_since?->toIso8601String())->toBe('2026-10-21T12:00:00+00:00');
    expect($check->latest_run_id)->toBe($previous->id);
    expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1);
    expect(app(NoResultFreshness::class)->forCheck($check, CarbonImmutable::now()))->toBe($down
        ? ['action' => 'suppressed', 'reason' => 'uptime_incident']
        : ['action' => 'counts', 'reason' => 'freshness.no_result.mixed_content_start_page']);
})->with(['site up' => false, 'already alerted by uptime' => true]);

it('crawls apex and www start redirects as one site', function (string $start, array $hops, string $final): void {
    $site = Site::factory()->create(['url' => $start]);
    $broken = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    $fake = new FakeOutboundHttp()->respondUsing(static function (FetchRequest $request) use ($hops, $final): FetchResult {
        if (isset($hops[$request->url])) {
            return new FetchResult(308, headers: ['Location' => [$hops[$request->url]]]);
        }

        return match ($request->url) {
            $final => new FetchResult(200, '<a href="/missing">Missing</a><img src="http://assets.example.net/image.png">', ['Content-Type' => ['text/html']]),
            new Url('/missing', new Url($final))->toAsciiString() => new FetchResult(404, headers: ['Content-Type' => ['text/html']]),
            default => throw new LogicException('Unexpected crawl redirect request: '.$request->url),
        };
    });
    app()->instance(OutboundHttp::class, $fake);
    expect(app(SameSite::class)->isSameSite($start, $final))->toBeTrue();
    expect($fake->requests)->toBeEmpty();

    $brokenRun = recordHeadlineCrawl($broken);
    $mixedRun = recordHeadlineCrawl($mixed);

    expect($brokenRun->metrics['broken_internal'])->toBe(1);
    expect($brokenRun->evidence['observed_pages'])->toBeGreaterThan(0);
    expect($mixedRun->result)->toBe(RunResult::Failed);
    expect($mixedRun->metrics['mixed_content'])->toBe(1);
    expect(count(array_filter($fake->requests, static fn (array $record): bool => $record['request']->url === $start)))->toBe(1);
})->with([
    'plain HTTPS collects once' => ['https://example.com/', [], 'https://example.com/'],
    'apex to www' => ['https://example.com/', ['https://example.com/' => 'https://www.example.com/'], 'https://www.example.com/'],
    'www to apex' => ['https://www.example.com/', ['https://www.example.com/' => 'https://example.com/'], 'https://example.com/'],
    'private suffix tenant' => ['https://tenant.github.io/', ['https://tenant.github.io/' => 'https://www.tenant.github.io/'], 'https://www.tenant.github.io/'],
    'multi-label suffix' => ['https://example.co.uk/', ['https://example.co.uk/' => 'https://www.example.co.uk/'], 'https://www.example.co.uk/'],
    'http to https' => ['http://example.com/', ['http://example.com/' => 'https://example.com/'], 'https://example.com/'],
    'same-host path' => ['https://example.com/', ['https://example.com/' => 'https://example.com/home'], 'https://example.com/home'],
    'unknown suffix same host' => ['https://example.invalid/', ['https://example.invalid/' => 'https://example.invalid/home'], 'https://example.invalid/home'],
    'same-site multi-hop' => ['http://example.com/', ['http://example.com/' => 'https://example.com/', 'https://example.com/' => 'https://www.example.com/'], 'https://www.example.com/'],
]);

it('errors both crawl checks at the first off-site start redirect without fetching it', function (string $target, bool $viaWww, string $start = 'https://example.com/', ?string $expectedTarget = null): void {
    $expectedTarget ??= $target;
    Mail::fake();
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $site = Site::factory()->create(['url' => $start]);
    $broken = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    $fake = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        $start => new FetchResult(301, headers: ['Location' => [$viaWww ? 'https://www.example.com/' : $target]]),
        'https://www.example.com/' => new FetchResult(301, headers: ['Location' => [$target]]),
        default => throw new LogicException('Off-site target was requested: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $fake);

    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->create(['id' => '00000000-0000-0000-0000-000000000094', 'last_seen_at' => now()]);
    CheckRun::factory()->create(['ended_at' => now()]);
    foreach ([$broken, $mixed] as $check) {
        $check->update(['awaiting_result_since' => now()->subDays(3)]);
        $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
        $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->where('scope', (string) $check->id)->sole();

        $run = recordHeadlineCrawl($check);
        expect($run->result)->toBe(RunResult::Errored);
        expect($run->summary)->toBe('crawl.start_redirect_off_site');
        expect($run->summary_params)->toBe(['start_redirect_target_url' => $expectedTarget]);
        expect(app(CheckFreshness::class)->state($check->refresh(), now())->state)->toBe('fresh');
        $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
        expect($issue->refresh()->resolved_at)->not->toBeNull();
        expect($issue->details['resolution'])->toBe('result_accepted');
        $this->assertDatabaseMissing('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $check->id, 'resolved_at' => null]);
        $attempt = CheckRunAttempt::query()->where('check_run_id', $run->id)->sole();
        $this->withToken($owner->createToken('redirect-read', ['checks:read'])->plainTextToken)
            ->getJson('/api/runs/attempt:'.$attempt->id)
            ->assertJsonPath('result.headline_data.start_redirect_target_url.0', $expectedTarget)
            ->assertJsonPath('result.headline_parts.reasons.0.untrusted_fields.start_redirect_target_url', 'start_redirect_target_url')
            ->assertJsonPath('result.display_headline_untrusted', true);
    }
    $this->actingAs($owner)->get('/sites/'.$site->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page
        ->where('start_redirect_target_url', $expectedTarget)
        ->where('site.url', $start));
    expect(array_map(static fn (array $record): string => $record['request']->url, $fake->requests))->not->toContain($target);
    $broken->update(['enabled' => false]);
    $mixed->update(['enabled' => false]);
    $this->actingAs($owner)->get('/sites/'.$site->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page
        ->where('start_redirect_target_url', null));
})->with([
    'another registrable domain' => ['https://other.test/', false],
    'overlong Location is capped' => ['https://other.test/'.str_repeat('a', 600), false, 'https://example.com/', 'https://other.test/'.str_repeat('a', 381).'#d865ce4fa2a132c70b2f3ac0dc465402e67a5d85398289ac12367518cfc0e710'],
    'HTTP start to another domain' => ['https://other.test/', false, 'http://example.com/'],
    'other subdomain' => ['https://shop.example.com/', false],
    'do not walk hosts through www' => ['https://other.test/', true],
    'www below another subdomain' => ['https://www.shop.example.com/', false, 'https://shop.example.com/'],
    'port change' => ['https://example.com:8443/', false],
    'scheme downgrade' => ['http://example.com/', false],
    'public suffix is not an apex' => ['https://www.co.uk/', false, 'https://co.uk/'],
    'unknown suffix alias' => ['https://www.example.invalid/', false, 'https://example.invalid/'],
    'private suffix itself' => ['https://www.github.io/', false, 'https://github.io/'],
]);

it('limits email-protection exclusions to the shared same-site rule', function (string $siteUrl, string $emailUrl, int $externalCount): void {
    $site = Site::factory()->create(['url' => $siteUrl]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link'], 'check_include_external_links' => true])]);
    $fake = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        $siteUrl => new FetchResult(200, '<a href="'.$emailUrl.'">Contact</a>', ['Content-Type' => ['text/html']]),
        $emailUrl => new FetchResult(404),
        default => throw new LogicException('Unexpected email-protection request: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $fake);

    $run = recordHeadlineCrawl($check);

    expect($run->metrics['external'])->toBe($externalCount);
    expect($fake->requests)->toHaveCount(1 + $externalCount);
})->with([
    'www below another subdomain stays visible' => ['https://shop.example.com/', 'https://www.shop.example.com/cdn-cgi/l/email-protection', 1],
    'registrable apex www alias stays excluded' => ['https://example.com/', 'https://www.example.com/cdn-cgi/l/email-protection', 0],
    'default-port HTTPS upgrade stays excluded' => ['http://example.com/', 'https://example.com/cdn-cgi/l/email-protection', 0],
]);

it('keeps an HTTP mixed-content failure incident open when collection next errors', function (bool $redirect, string $summary): void {
    $site = Site::factory()->create(['url' => 'http://example.com/']);
    Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent, 'failure_threshold' => 1]);
    $fake = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'http://example.com/' => new FetchResult(200, '<p>Readable HTTP page</p>', ['Content-Type' => ['text/html']]),
        default => throw new LogicException('Unexpected HTTP start request: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $fake);
    $initial = recordHeadlineCrawl($mixed);
    expect($initial->result)->toBe(RunResult::Failed);
    expect($initial->summary)->toBe('mixed_content.not_https');
    $incident = Incident::query()->where('check_id', $mixed->id)->whereNull('resolved_at')->sole();
    expect($incident->cause)->toBe(IncidentCause::Failed);
    DB::table('crawl_collections')->where('site_id', $site->id)->delete();
    $unavailableHttp = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'http://example.com/' => $redirect ? new FetchResult(301, headers: ['Location' => ['https://other.test/']])
            : new FetchResult(failure: new FetchFailure(FetchFailureKind::Connect)),
        default => throw new LogicException('Off-site HTTP start target was requested: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $unavailableHttp);
    $this->travelTo(CarbonImmutable::parse('2026-10-21T13:00:00Z'));

    $errored = recordHeadlineCrawl($mixed);

    expect($errored->result)->toBe(RunResult::Errored);
    expect($errored->summary)->toBe($summary);
    expect($incident->refresh()->resolved_at)->toBeNull();
    expect($incident->cause)->toBe(IncidentCause::Errored);
    expect($incident->requiresCompleteRecovery())->toBeTrue();
    expect(Incident::query()->where('check_id', $mixed->id)->whereNull('resolved_at')->sole()->id)->toBe($incident->id);
    $this->assertDatabaseMissing('events', ['check_id' => $mixed->id, 'type' => 'incident.resolved']);
    expect($fake->requests)->toHaveCount(1);
    expect($unavailableHttp->requests)->toHaveCount(1);
})->with([
    'HTTP start redirects off-site' => [true, 'crawl.start_redirect_off_site'],
    'HTTP start cannot connect' => [false, 'crawl.start_page_unreachable.connect'],
]);

it('keeps a plain HTTP start failure when same-host HTTPS descendants are scanned', function (bool $httpsLink): void {
    $site = Site::factory()->create(['url' => 'http://example.com/']);
    Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent, 'failure_threshold' => 1]);
    app()->instance(OutboundHttp::class, new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'http://example.com/' => new FetchResult(200, '<p>HTTP baseline</p>', ['Content-Type' => ['text/html']]),
        default => throw new LogicException('Unexpected baseline request: '.$request->url),
    }));
    recordHeadlineCrawl($mixed);
    $incident = Incident::query()->where('check_id', $mixed->id)->whereNull('resolved_at')->sole();
    DB::table('crawl_collections')->where('site_id', $site->id)->delete();
    $http = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'http://example.com/' => new FetchResult(200, $httpsLink ? '<a href="https://example.com/x">HTTPS descendant</a>' : '<p>Still HTTP</p>', ['Content-Type' => ['text/html']]),
        'https://example.com/x' => new FetchResult(200, '<p>HTTPS child</p>', ['Content-Type' => ['text/html']]),
        default => throw new LogicException('Unexpected descendant request: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $http);
    $this->travelTo(CarbonImmutable::parse('2026-10-21T13:00:00Z'));

    $run = recordHeadlineCrawl($mixed);

    expect($run->result)->toBe(RunResult::Failed);
    expect($run->summary)->toBe('mixed_content.not_https');
    expect($incident->refresh()->resolved_at)->toBeNull();
    expect($incident->cause)->toBe(IncidentCause::Failed);
    $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    expect($artifact['start_page_final_url'])->toBe('http://example.com/');
    expect($http->requests)->toHaveCount($httpsLink ? 2 : 1);
    $cachedHttp = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $cachedHttp);
    $cached = recordHeadlineCrawl($mixed);
    expect($cached->result)->toBe(RunResult::Failed);
    expect($cached->summary)->toBe('mixed_content.not_https');
    expect($cachedHttp->requests)->toBeEmpty();
    expect($incident->refresh()->resolved_at)->toBeNull();
})->with(['plain HTTP control' => false, 'HTTP links to HTTPS' => true]);

it('retains the HTTP start failure and cache reuse when robots prevents a start response', function (): void {
    $site = Site::factory()->create(['url' => 'http://example.com/']);
    Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => true, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    $http = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'http://example.com/robots.txt' => new FetchResult(200, "User-agent: *\nDisallow: /\n", ['Content-Type' => ['text/plain']]),
        default => throw new LogicException('Robots-disallowed start was requested: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $http);

    $run = recordHeadlineCrawl($mixed);

    expect($run->summary)->toBe('mixed_content.not_https');
    expect($run->result)->toBe(RunResult::Failed);
    $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    expect($artifact['start_page_final_url'])->toBeNull();
    $cachedHttp = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $cachedHttp);
    expect(recordHeadlineCrawl($mixed)->summary)->toBe('mixed_content.not_https');
    expect($cachedHttp->requests)->toBeEmpty();
});

it('stops same-site origin changes before crawler headers can reach the redirect target', function (string $start, string $target): void {
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $site = Site::factory()->create(['url' => $start, 'crawler_headers' => [['name' => 'Authorization', 'value' => 'private-crawler-token']]]);
    $broken = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    $http = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        $start => new FetchResult(308, headers: ['Location' => [$target]]),
        $target => new FetchResult(401),
        default => throw new LogicException('Unexpected credential redirect request: '.$request->url),
    });
    app()->instance(OutboundHttp::class, $http);

    foreach ([$broken, $mixed] as $check) {
        $run = recordHeadlineCrawl($check);
        expect($run->result)->toBe(RunResult::Errored);
        expect($run->summary)->toBe('crawl.start_redirect_credentials_origin');
        expect($run->summary_params)->toBe(['start_redirect_target_url' => $target]);
        $attempt = CheckRunAttempt::query()->where('check_run_id', $run->id)->sole();
        $this->withToken($owner->createToken('credential-redirect-read', ['checks:read'])->plainTextToken)
            ->getJson('/api/runs/attempt:'.$attempt->id)
            ->assertJsonPath('result.headline_data.start_redirect_target_url.0', $target)
            ->assertJsonPath('result.headline_parts.reasons.0.untrusted_fields.start_redirect_target_url', 'start_redirect_target_url')
            ->assertJsonPath('result.display_headline_untrusted', true);
    }
    expect(array_map(static fn (array $entry): string => $entry['request']->url, $http->requests))->toBe([$start, $start]);
})->with([
    'apex to www' => ['https://example.com/', 'https://www.example.com/'],
    'HTTP to HTTPS' => ['http://example.com/', 'https://example.com/'],
]);

it('uses the start redirect response URL even when the HTTPS destination is not readable HTML', function (int $status, array $headers, string $summary): void {
    $site = Site::factory()->create(['url' => 'http://example.com/']);
    Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    app()->instance(OutboundHttp::class, new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'http://example.com/' => new FetchResult(308, headers: ['Location' => ['https://example.com/']]),
        'https://example.com/' => new FetchResult($status, headers: $headers),
        default => throw new LogicException('Unexpected unreadable redirect request: '.$request->url),
    }));

    $run = recordHeadlineCrawl($mixed);

    expect($run->summary)->toBe($summary);
    $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    expect($artifact['start_page_final_url'])->toBe('https://example.com/');
    expect($artifact['observed_pages'])->toBeEmpty();
})->with([
    'bot protection' => [403, ['Content-Type' => ['text/html'], 'cf-mitigated' => ['challenge']], 'crawl.not_crawlable.bot_protection.not_checked'],
    'non-HTML' => [200, ['Content-Type' => ['image/png']], 'crawl.not_crawlable.non_html.not_checked'],
]);

it('keeps oversized start statuses usable without guessing an unscanned start scheme', function (?string $finalUrl, string $mime, ?string $mixedSummary, string $start = 'https://example.com/'): void {
    $site = Site::factory()->create(['url' => $start]);
    $broken = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'types' => ['link']])]);
    $mixed = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    $http = new FakeOutboundHttp()->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->method.' '.$request->url) {
        'GET http://example.com/' => new FetchResult(308, headers: ['Location' => ['https://example.com/']]),
        'GET https://example.com/' => new FetchResult(failure: new FetchFailure(FetchFailureKind::TooLarge, 200)),
        'HEAD '.$start => new FetchResult(200, headers: ['Content-Type' => [$mime]], effectiveUrl: $finalUrl),
        default => throw new LogicException('Unexpected oversized-root request: '.$request->method.' '.$request->url),
    });
    app()->instance(OutboundHttp::class, $http);

    $run = recordHeadlineCrawl($broken);

    expect($run->result)->toBe(RunResult::Succeeded);
    $artifact = json_decode(DB::table('crawl_collections')->where('site_id', $site->id)->value('artifact'), true, flags: JSON_THROW_ON_ERROR);
    expect($artifact['start_page_final_url'])->toBe($finalUrl);
    expect($artifact['start_page_too_large'])->toBeTrue();
    $claimed = ClaimedRun::start($mixed);
    $context = new CheckContext($claimed->siteId, $claimed->checkId, $claimed->runId, $claimed->claimToken, $claimed->url,
        $claimed->startedAt, CarbonImmutable::parse('2100-01-01T00:00:00Z'), $claimed->config, $claimed->crawlerHeaders, $claimed->previousResult);
    $outcome = app(CheckRegistry::class)->get(CheckKey::MixedContent)->execute($context);
    if ($mixedSummary === null) {
        expect($outcome)->toBe(NoResultReason::MixedContentStartPageNotScanned);
    } else {
        expect($outcome->summary)->toBe($mixedSummary);
        expect($outcome->result)->toBe(RunResult::Failed);
    }
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($mixed->id, $context->claimToken,
        $context->startedAt, $context->startedAt->addSecond(), $outcome))->accepted)->toBeTrue();
    expect($http->requests)->toHaveCount($start === 'http://example.com/' ? 3 : 2);
})->with([
    'HTTP upgrade then HEAD identifies HTTPS' => ['https://example.com/', 'text/html', null, 'http://example.com/'],
    'HEAD identifies HTTPS' => ['https://example.com/', 'text/html', null],
    'no final-URL source' => [null, 'text/html', null],
    'confirmed non-HTML without final URL' => [null, 'application/pdf', null],
    'HEAD identifies HTTP' => ['http://example.com/', 'text/html', 'mixed_content.not_https'],
]);

it('shows only the latest enabled crawl result start-redirect hint', function (string $summary, bool $newerSuccess): void {
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $site = Site::factory()->create();
    $error = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks, 'latest_run_ended_at' => now()->subMinute(),
        'latest_run_result' => CheckResult::Errored, 'summary' => $summary,
        'summary_params' => ['start_redirect_target_url' => 'https://www.example.com/']]);
    Check::factory()->for($site)->create(['type' => CheckKey::MixedContent, 'latest_run_ended_at' => $newerSuccess ? now() : now()->subMinutes(2),
        'latest_run_result' => CheckResult::Succeeded, 'summary' => 'mixed_content.succeeded', 'summary_params' => []]);

    $this->actingAs($owner)->get('/sites/'.$site->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page
        ->where('start_redirect_summary_key', $newerSuccess ? null : $summary)
        ->where('start_redirect_target_url', $newerSuccess ? null : 'https://www.example.com/'));

    $error->update(['enabled' => false]);
    $this->actingAs($owner)->get('/sites/'.$site->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page
        ->where('start_redirect_summary_key', null)
        ->where('start_redirect_target_url', null));
})->with([
    'newest off-site error' => ['crawl.start_redirect_off_site', false],
    'newest credential-origin error' => ['crawl.start_redirect_credentials_origin', false],
    'newer success clears older off-site hint' => ['crawl.start_redirect_off_site', true],
    'newer success clears older credential hint' => ['crawl.start_redirect_credentials_origin', true],
]);

/** @param array<string, mixed> $metadata
 * @return array<string, mixed>
 */
function crawlNoteArtifact(CheckContext $context, array $metadata): array
{
    $artifact = new CrawlArtifact($context->url, $context->startedAt->toIso8601String(), ['max_pages' => 2000], [], [],
        str_repeat('a', 64), false, [], [], [], true, config_fingerprint: str_repeat('b', 64),
        classification_policy_version: LinkClassificationPolicy::VERSION);

    return [...$artifact->privateStorage(), 'evaluated_finding_ids' => [],
        'notes' => [['key' => 'crawl.collection_reused', 'params' => ['date' => $context->startedAt->toIso8601String()], 'labels' => [], ...$metadata]]];
}

it('stages crawl notes with legacy or typed list metadata', function (array $metadata): void {
    $context = ClaimedRun::start(Check::factory()->create(['type' => CheckKey::BrokenLinks]));

    app(CrawlPersistence::class)->stage($context, 'artifact', [crawlNoteArtifact($context, $metadata)]);

    $this->assertDatabaseHas('crawl_snapshots', ['run_id' => $context->runId, 'claim_token' => $context->claimToken, 'check_id' => $context->checkId]);
})->with([
    'legacy note without lists' => [[]],
    'empty list metadata' => [['lists' => []]],
    'named string list' => [['lists' => ['types' => ['A', 'AAAA']]]],
]);

it('rejects malformed crawl note lists before staging an artifact', function (mixed $lists, string $field): void {
    $context = ClaimedRun::start(Check::factory()->create(['type' => CheckKey::BrokenLinks]));

    try {
        app(CrawlPersistence::class)->stage($context, 'artifact', [crawlNoteArtifact($context, ['lists' => $lists])]);
        $this->fail('Malformed presentation lists must not be staged.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($field);
    }

    $this->assertDatabaseMissing('crawl_snapshots', ['run_id' => $context->runId]);
})->with([
    'metadata is not an array' => ['A', 'notes.0.lists'],
    'numeric list name' => [[['A']], 'notes.0.lists'],
    'list is not an array' => [['types' => 'A'], 'notes.0.lists.types'],
    'list has named entries' => [['types' => ['first' => 'A']], 'notes.0.lists.types'],
    'list has non-string entry' => [['types' => [42]], 'notes.0.lists.types.0'],
]);
