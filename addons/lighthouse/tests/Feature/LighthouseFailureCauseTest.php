<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\RenderedAlert;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunResult;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Support\Api\TrustedSummary;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Pest.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22T12:00:00Z'));
});

/** @param array<string, mixed> $body */
function lighthouseFailureHttp(array $body = [], ?FetchFailure $failure = null): FakeOutboundHttp
{
    config(['lighthouse.service_url' => 'http://lighthouse.test:3000', 'lighthouse.proxy_control_url' => 'http://proxy-control.test:3129']);
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request, CheckContext $context) use ($body, $failure): FetchResult {
        if ($request->url === 'http://proxy-control.test:3129/scopes' && $request->method === 'POST') {
            return new FetchResult(200, json_encode(['token' => str_repeat('a', 64)], JSON_THROW_ON_ERROR));
        }
        if ($request->url === 'http://proxy-control.test:3129/scopes/'.str_repeat('a', 64) && $request->method === 'DELETE') {
            return new FetchResult(204);
        }
        if ($request->url === 'http://proxy-control.test:3129/scopes/'.str_repeat('a', 64).'?host=example.com' && $request->method === 'GET') {
            return new FetchResult(200, '{"blockedTarget":false}');
        }
        if ($request->url !== 'http://lighthouse.test:3000/lighthouse' || $request->method !== 'POST') {
            throw new LogicException('Unexpected Lighthouse HTTP request.');
        }

        return new FetchResult($failure instanceof FetchFailure ? $failure->status : 200, $failure instanceof FetchFailure ? '' : json_encode($body, JSON_THROW_ON_ERROR), failure: $failure);
    });
    app()->instance(OutboundHttp::class, $fake);

    return $fake;
}

it('records each audit ending as a named check error with bounded redacted diagnostics and no scores', function (string $code, string $summary): void {
    $failure = ['code' => $code, 'stage' => 'gathering', 'message' => 'Diagnostic private-header at https://example.com/?token=secret-value'];
    if ($code === 'audit_budget_exceeded') {
        $failure['budgetSeconds'] = 120;
    }
    lighthouseFailureHttp(['auditFailure' => $failure]);
    $check = lighthouseCheck(['http_client_headers' => [['name' => 'Authorization', 'value' => 'private-header']]]);
    $check->site->update(['url' => 'https://example.com/?token=secret-value']);
    $run = runLighthouse($check);

    expect($run->summary)->toBe($summary);
    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    expect($run->summary_params['stage'])->toBe('gathering');
    expect($run->summary_params['message'])->toContain('[redacted]');
    expect($run->summary_params['message'])->not->toContain('private-header');
    expect($run->summary_params['message'])->not->toContain('secret-value');
    if ($code === 'audit_budget_exceeded') {
        expect($run->summary_params['seconds'])->toBe(120);
    }
    expect(DB::table('lighthouse_reports')->count())->toBe(0);
})->with([
    ['audit_budget_exceeded', 'lighthouse.cause.audit_budget_exceeded'],
    ['page_hung', 'lighthouse.cause.page_hung'],
    ['page_crashed', 'lighthouse.cause.page_crashed'],
    ['no_content', 'lighthouse.cause.no_content'],
    ['not_html', 'lighthouse.cause.not_html'],
    ['page_load_failed', 'lighthouse.cause.page_load_failed'],
    ['report_unscored', 'lighthouse.cause.report_unscored'],
    ['report_too_large', 'lighthouse.cause.report_too_large'],
    ['audit_incomplete', 'causes.engine.lighthouse_incomplete'],
]);

it('persists diagnostics without overlapping or transformed header secrets', function (array $secrets, string $diagnostic, string $expected = '[redacted]'): void {
    lighthouseFailureHttp(['auditFailure' => ['code' => 'page_hung', 'message' => 'Before '.$diagnostic.' | '.$diagnostic.' after']]);
    $headers = [];
    foreach ($secrets as $index => $secret) {
        $headers[] = ['name' => 'X-Secret-'.$index, 'value' => $secret];
    }
    $check = lighthouseCheck(['http_client_headers' => $headers]);

    $run = runLighthouse($check);

    expect($run->result)->toBe(RunResult::Errored);
    expect($run->summary)->toBe('lighthouse.cause.page_hung');
    expect($run->summary_params['message'])->toBe('Before '.$expected.' | '.$expected.' after');
})->with([
    'equal-length overlaps' => [['abcdefgh', 'efghijkl'], 'abcdefghijkl'],
    'longer right overlap' => [['abcdefgh', 'fghijklmnop'], 'abcdefghijklmnop'],
    'longer left overlap' => [['abcdefghijkl', 'ijklmnop'], 'abcdefghijklmnop'],
    'chained overlaps' => [['abcdefgh', 'efghijkl', 'ijklmnop'], 'abcdefghijklmnop'],
    'self overlaps' => [['abcabcabc'], 'abcabcabcabcabc'],
    'URI-encoded overlaps' => [['abcd/efgh', 'efgh/ijkl'], 'abcd%2fefgh%2Fijkl'],
    'form-encoded overlaps' => [['abcd efgh', 'efgh ijkl'], 'abcd+efgh+ijkl'],
    'JSON-escaped overlaps' => [['abcd"efgh', 'efgh"ijkl'], 'abcd\\"efgh\\"ijkl'],
    'decoded overlaps' => [['alpha%20beta', 'betaGAMMA'], 'alpha betaGAMMA'],
    'filtered Unicode controls' => [["CONTROL\u{0085}SECRET"], 'CONTROLSECRET'],
    'decoded query' => [['DECODED%20SECRET'], 'DECODED SECRET'],
    'decoded query plus' => [['DECODED+SECRET'], 'DECODED SECRET'],
    'decoded literal plus' => [['DECODED+%20SECRET'], 'DECODED+ SECRET'],
    'decoded and filtered controls' => [['DECODED%0ASECRET'], 'DECODEDSECRET'],
    'control-split plain secret' => [['abcdefgh'], "abcd\n\tefgh"],
    'control-split multibyte secret' => [['ab😀cdefgh'], "ab😀c\u{0085}defgh"],
    'control-split encoded secret' => [['abcd/efgh'], "abcd%2F\n\tefgh"],
    'overlap between original and filtered matches' => [['abcdefgh', 'fghijklmnop'], "abcd\n\tefghijklmnop"],
    'controls outside the matched range' => [['abcdefgh'], "\nabcdefgh\t", "\n[redacted]\t"],
    'unmatched controls remain unchanged' => [['zzzz'], "a\nbc", "a\nbc"],
]);

it('records an old service bare 502 as an incomplete audit', function (): void {
    lighthouseFailureHttp(failure: new FetchFailure(FetchFailureKind::HttpStatus, 502));

    expect(runLighthouse(lighthouseCheck())->summary)->toBe('causes.engine.lighthouse_incomplete');
});

it('does not blame the page when the audit service cannot be reached', function (FetchFailureKind $kind): void {
    lighthouseFailureHttp(failure: new FetchFailure($kind));

    expect(runLighthouse(lighthouseCheck())->summary)->toBe('lighthouse.cause.service_unavailable');
})->with([FetchFailureKind::Connect, FetchFailureKind::Dns, FetchFailureKind::Timeout]);

it('updates an errored incident to the latest named cause without a cause-changed event', function (): void {
    $check = lighthouseCheck();
    lighthouseFailureHttp(failure: new FetchFailure(FetchFailureKind::HttpStatus, 502));
    runLighthouse($check);
    $incident = Incident::query()->where('check_id', $check->id)->whereNull('resolved_at')->sole();
    lighthouseFailureHttp(['auditFailure' => ['code' => 'page_hung', 'stage' => 'navigation', 'message' => null]]);

    $run = runLighthouse($check);

    expect($run->summary)->toBe('lighthouse.cause.page_hung');
    expect(Incident::query()->where('check_id', $check->id)->whereNull('resolved_at')->sole()->id)->toBe($incident->id);
    expect(DB::table('events')->where('check_id', $check->id)->where('type', 'incident.cause_changed')->count())->toBe(0);
});

it('does not blame the page when scope creation cannot reach our proxy', function (): void {
    config(['lighthouse.proxy_control_url' => 'http://proxy-control.test:3129']);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request): FetchResult {
        if ($request->url !== 'http://proxy-control.test:3129/scopes' || $request->method !== 'POST') {
            throw new LogicException('Unexpected request.');
        }

        return new FetchResult(failure: new FetchFailure(FetchFailureKind::Connect));
    }));

    expect(runLighthouse(lighthouseCheck())->summary)->toBe('lighthouse.cause.service_unavailable');
});

it('preserves named runtime endings after checking the navigation policy', function (string $code, string $summary): void {
    lighthouseFailureHttp(['runtimeError' => ['code' => $code, 'message' => 'Runtime detail'], 'target_host' => 'example.com']);

    $run = runLighthouse(lighthouseCheck());

    expect($run->summary)->toBe($summary);
    expect($run->summary_params)->toBe(['message' => 'Runtime detail']);
    expect($run->metrics)->toBeNull();
})->with([
    ['page_hung', 'lighthouse.cause.page_hung'],
    ['page_crashed', 'lighthouse.cause.page_crashed'],
    ['no_content', 'lighthouse.cause.no_content'],
    ['not_html', 'lighthouse.cause.not_html'],
    ['page_load_failed', 'lighthouse.cause.page_load_failed'],
    ['audit_incomplete', 'causes.engine.lighthouse_incomplete'],
]);

it('retains the target network cause instead of blaming our service for a native navigation failure', function (string $code, string $summary): void {
    lighthouseFailureHttp(['runtimeError' => ['code' => $code, 'message' => 'Native error'], 'target_host' => 'example.com']);

    expect(runLighthouse(lighthouseCheck())->summary)->toBe($summary);
})->with([
    ['ERR_CONNECTION_REFUSED', 'causes.outbound.connect'],
    ['ERR_NAME_NOT_RESOLVED', 'causes.outbound.dns'],
    ['ERR_TIMED_OUT', 'causes.outbound.timeout'],
]);

it('rejects invalid service diagnostics at the renderer boundary', function (array $failure, string $message): void {
    lighthouseFailureHttp(['auditFailure' => $failure]);

    $run = runLighthouse(lighthouseCheck());

    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe($message);
})->with([
    'unknown code' => [['code' => 'unknown'], 'Invalid Lighthouse failure code.'],
    'unknown stage' => [['code' => 'page_hung', 'stage' => 'raw stage'], 'Invalid Lighthouse failure stage.'],
    'non-string diagnostic' => [['code' => 'page_hung', 'message' => 42], 'Invalid Lighthouse failure message.'],
    'oversize diagnostic' => [['code' => 'page_hung', 'message' => str_repeat('ü', 501)], 'Invalid Lighthouse failure message.'],
    'non-string event' => [['code' => 'page_hung', 'last_unrecognised_event' => 42], 'Invalid Lighthouse unrecognised event.'],
    'null event' => [['code' => 'page_hung', 'last_unrecognised_event' => null], 'Invalid Lighthouse unrecognised event.'],
    'oversize event' => [['code' => 'page_hung', 'last_unrecognised_event' => str_repeat('A', 65)], 'Invalid Lighthouse unrecognised event.'],
    'URL event' => [['code' => 'page_hung', 'last_unrecognised_event' => 'Event: https://private.test'], 'Invalid Lighthouse unrecognised event.'],
    'dotted event' => [['code' => 'page_hung', 'last_unrecognised_event' => 'Fetching robots for example.com'], 'Invalid Lighthouse unrecognised event.'],
    'empty event' => [['code' => 'page_hung', 'last_unrecognised_event' => ''], 'Invalid Lighthouse unrecognised event.'],
    'control event' => [['code' => 'page_hung', 'last_unrecognised_event' => "Event\n"], 'Invalid Lighthouse unrecognised event.'],
    'unknown stage seconds' => [['code' => 'page_hung', 'stageSeconds' => ['raw' => 1]], 'Invalid Lighthouse stage seconds.'],
    'negative stage seconds' => [['code' => 'page_hung', 'stageSeconds' => ['navigation' => -1]], 'Invalid Lighthouse stage seconds.'],
    'string stage seconds' => [['code' => 'page_hung', 'stageSeconds' => ['navigation' => '1']], 'Invalid Lighthouse stage seconds.'],
    'nested stage seconds' => [['code' => 'page_hung', 'stageSeconds' => ['navigation' => ['seconds' => 1]]], 'Invalid Lighthouse stage seconds.'],
    'null stage seconds' => [['code' => 'page_hung', 'stageSeconds' => null], 'Invalid Lighthouse stage seconds.'],
    'list stage seconds' => [['code' => 'page_hung', 'stageSeconds' => [1]], 'Invalid Lighthouse stage seconds.'],
    'scalar stage seconds' => [['code' => 'page_hung', 'stageSeconds' => 1], 'Invalid Lighthouse stage seconds.'],
    'missing budget' => [['code' => 'audit_budget_exceeded'], 'Invalid Lighthouse audit budget.'],
    'string budget' => [['code' => 'audit_budget_exceeded', 'budgetSeconds' => '120'], 'Invalid Lighthouse audit budget.'],
    'zero budget' => [['code' => 'audit_budget_exceeded', 'budgetSeconds' => 0], 'Invalid Lighthouse audit budget.'],
    'float budget' => [['code' => 'audit_budget_exceeded', 'budgetSeconds' => 120.5], 'Invalid Lighthouse audit budget.'],
    'unexpected budget' => [['code' => 'page_hung', 'budgetSeconds' => 120], 'Unexpected Lighthouse audit budget.'],
]);

it('retains a 500-character diagnostic and projects stage and diagnostic as secondary facts', function (): void {
    lighthouseFailureHttp(['auditFailure' => ['code' => 'audit_budget_exceeded', 'stage' => 'gathering', 'message' => (str_repeat('ü', 499).'😀'), 'budgetSeconds' => 120]]);
    $check = lighthouseCheck();

    $run = runLighthouse($check);
    $presentation = app(CheckRunPresentations::class)->forRun($check, $run);
    $summary = TrustedSummary::project($run->summary, $run->summary_params);

    expect($run->summary_params['message'])->toBe(str_repeat('ü', 499).'😀');
    expect($presentation->parts[0]->key)->toBe('lighthouse.cause.audit_budget_exceeded');
    expect($presentation->details[0]->key)->toBe('lighthouse.stopped_during');
    expect($presentation->details[0]->labels)->toBe(['stage_label' => 'lighthouse.stage.gathering']);
    expect($presentation->details[1]->untrustedFields())->toBe(['message' => 'error_message']);
    expect($presentation->details)->toHaveCount(2);
    expect((array) $summary['params'])->toBe(['seconds' => 120]);
    expect((array) $summary['untrusted_params'])->toBe(['stage' => 'gathering', 'message' => str_repeat('ü', 499).'😀']);
});

it('exposes the recorded stage and diagnostic in both run-history and check-page presentations', function (): void {
    $user = User::factory()->owner()->create(['locale' => 'en']);
    lighthouseFailureHttp(['auditFailure' => ['code' => 'page_hung', 'stage' => 'navigation', 'message' => 'Recorded diagnostic']]);
    $check = lighthouseCheck();
    runLighthouse($check);

    $this->actingAs($user)->get('/runs?check='.$check->id)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('runs.0.presentation.parts.0.key', 'lighthouse.cause.page_hung')
        ->where('runs.0.presentation.details.0.labels.stage_label', 'lighthouse.stage.navigation')
        ->where('runs.0.presentation.details.1.params.message', 'Recorded diagnostic'));
    $this->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('check.presentation.parts.0.key', 'lighthouse.cause.page_hung')
        ->where('check.presentation.details.0.labels.stage_label', 'lighthouse.stage.navigation')
        ->where('check.presentation.details.1.params.message', 'Recorded diagnostic'));
});

it('does not blame the page when our proxy scope cannot be released', function (FetchFailureKind $kind): void {
    config(['lighthouse.service_url' => 'http://lighthouse.test:3000', 'lighthouse.proxy_control_url' => 'http://proxy-control.test:3129']);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => match (true) {
        $request->method === 'POST' && str_ends_with($request->url, '/scopes') => new FetchResult(200, json_encode(['token' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
        $request->method === 'POST' && str_ends_with($request->url, '/lighthouse') => new FetchResult(200, json_encode(lighthouseFixture(), JSON_THROW_ON_ERROR)),
        $request->method === 'DELETE' => new FetchResult(failure: new FetchFailure($kind, $kind === FetchFailureKind::HttpStatus ? 500 : null)),
        default => throw new LogicException('Unexpected request.'),
    }));
    $run = runLighthouse(lighthouseCheck());
    expect($run->summary)->toBe('lighthouse.cause.scope_release_failed');
    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    expect(DB::table('lighthouse_reports')->count())->toBe(0);
})->with([FetchFailureKind::Connect, FetchFailureKind::Dns, FetchFailureKind::Timeout, FetchFailureKind::HttpStatus]);

it('attributes proxy control failures to our pipeline', function (string $step, FetchFailureKind $kind, string $summary): void {
    config(['lighthouse.service_url' => 'http://lighthouse.test:3000', 'lighthouse.proxy_control_url' => 'http://proxy-control.test:3129']);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use ($step, $kind): FetchResult {
        if (($step === 'scope' && $request->method === 'POST' && str_ends_with($request->url, '/scopes')) || ($step === 'verdict' && $request->method === 'GET')) {
            return new FetchResult(failure: new FetchFailure($kind, $kind === FetchFailureKind::HttpStatus ? 500 : null));
        }

        return match (true) {
            $request->method === 'DELETE' => new FetchResult(204),
            str_ends_with($request->url, '/scopes') => new FetchResult(200, json_encode(['token' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
            str_ends_with($request->url, '/lighthouse') => new FetchResult(200, '{"runtimeError":{"code":"page_hung"},"target_host":"example.com"}'),
            default => throw new LogicException('Unexpected request.'),
        };
    }));
    $run = runLighthouse(lighthouseCheck());
    expect($run->summary)->toBe($summary);
    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    if ($kind === FetchFailureKind::HttpStatus) {
        expect($run->summary_params['message'])->toBe('proxy control HTTP 500');
    }
    if ($kind === FetchFailureKind::TooLarge) {
        expect($run->summary_params['message'])->toBe('proxy control too_large');
    }
})->with([
    ['verdict', FetchFailureKind::TooLarge, 'causes.engine.lighthouse_incomplete'],
    ['verdict', FetchFailureKind::Connect, 'lighthouse.cause.service_unavailable'],
    ['verdict', FetchFailureKind::Dns, 'lighthouse.cause.service_unavailable'],
    ['verdict', FetchFailureKind::Timeout, 'lighthouse.cause.service_unavailable'],
    ['scope', FetchFailureKind::HttpStatus, 'causes.engine.lighthouse_incomplete'],
    ['verdict', FetchFailureKind::HttpStatus, 'causes.engine.lighthouse_incomplete'],
]);

it('renders the bounded unrecognised event only as an untrusted secondary fact', function (): void {
    $event = str_repeat('A', 64);
    lighthouseFailureHttp(['auditFailure' => ['code' => 'page_hung', 'stage' => 'auditing', 'last_unrecognised_event' => $event]]);
    $check = lighthouseCheck();
    $run = runLighthouse($check);
    $presentation = app(CheckRunPresentations::class)->forRun($check, $run);
    expect($run->summary_params['last_unrecognised_event'])->toBe($event);
    expect($presentation->parts[0]->params)->not->toHaveKey('last_unrecognised_event');
    expect($presentation->details[1]->key)->toBe('lighthouse.last_unrecognised_event');
    expect($presentation->details[1]->untrustedFields())->toBe(['last_unrecognised_event' => 'last_unrecognised_event']);
    expect((array) TrustedSummary::project($run->summary, $run->summary_params)['untrusted_params'])->toBe(['stage' => 'auditing', 'last_unrecognised_event' => $event]);
});

it('records a load timeout as a warning with scores and resolves a check-error incident', function (): void {
    $check = lighthouseCheck();
    lighthouseFailureHttp(['auditFailure' => ['code' => 'page_hung']]);
    runLighthouse($check);
    $incident = Incident::query()->where('check_id', $check->id)->whereNull('resolved_at')->sole();
    lighthouseFailureHttp([...lighthouseFixture(), 'load_timed_out' => true]);

    $run = runLighthouse($check);

    expect($run->result)->toBe(RunResult::Warning);
    expect($run->summary)->toBe('lighthouse.load_timed_out');
    expect($run->metrics)->toMatchArray(['performance_score' => 100, 'accessibility_score' => 96, 'best_practices_score' => 100, 'seo_score' => 80]);
    expect($run->evidence['load_timed_out'])->toBeTrue();
    expect(DB::table('lighthouse_reports')->where('run_id', $run->id)->count())->toBe(1);
    expect($incident->refresh()->resolved_at)->not->toBeNull();
    expect(Incident::query()->where('check_id', $check->id)->whereNull('resolved_at')->count())->toBe(0);
    expect(app(CheckRunPresentations::class)->forRun($check, $run)->parts[0]->key)->toBe('lighthouse.presentation.load_timed_out');
});

it('names the score finding first and the load timeout second in headline, history and mail', function (bool $withBaseline, array $thresholds, array $keys, string $headline, string $historyLabel): void {
    $check = lighthouseCheck(['notification_settings' => $thresholds]);
    if ($withBaseline) {
        // The accepted fixture run (performance 100) becomes the drop baseline for the next run.
        lighthouseFailureHttp(lighthouseFixture());
        expect(runLighthouse($check)->result)->toBe(RunResult::Succeeded);
    }
    $report = lighthouseFixture();
    $report['categories']['performance']['score'] = 0.34;
    lighthouseFailureHttp([...$report, 'load_timed_out' => true]);

    $run = runLighthouse($check);

    expect($run->summary)->toBe('lighthouse.warning');
    $presentation = app(CheckRunPresentations::class)->forRun($check, $run);
    expect(array_map(static fn (CheckRunPresentationPartData $part): string => $part->key, $presentation->parts))->toBe($keys);
    expect(CheckHeadline::format($presentation, 'en', CheckKey::Lighthouse))->toBe('Lighthouse: '.$headline)->not->toContain('and 1 more');
    $history = $presentation->history_label ?? throw new LogicException('Mixed runs need a history label.');
    expect(CheckHeadline::format(new CheckRunPresentationData([$history]), 'en'))
        ->toBe($historyLabel);
    $event = Event::query()->where('type', 'check.result_changed')->where('payload->run_id', $run->id)->sole();
    $destination = NotificationDestination::factory()->create(['locale' => 'en']);
    $message = app(NotificationMessage::class)->build($event, $destination);
    expect($message->mailContext)->not->toBeNull();
    $mail = app(AlertPreviewRenderer::class)->render($message, 'en', 'UTC', 'Heptapod', 'https://heptapod.example');
    expect(html_entity_decode($mail['html'], ENT_QUOTES | ENT_HTML5))->toContain($headline)->not->toContain('and 1 more');
    expect(new RenderedAlert($message, 'en', 'UTC', 'Heptapod', 'https://heptapod.example')->headline())->toBe('Lighthouse: '.$headline);
})->with([
    'one score' => [false, ['performance' => 50],
        ['lighthouse.presentation.below', 'lighthouse.presentation.load_timed_out_continuation'],
        'Performance 34 (below 50), page loaded too slowly so scores may be incomplete.',
        'Performance below threshold, page loaded too slowly so scores may be incomplete.'],
    'two scores name all three parts' => [false, ['performance' => 50, 'seo' => 100],
        ['lighthouse.presentation.below', 'lighthouse.presentation.load_timed_out_continuation', 'lighthouse.presentation.below'],
        'Performance 34 (below 50), page loaded too slowly so scores may be incomplete, SEO 80 (below 100).',
        'Performance below threshold, page loaded too slowly so scores may be incomplete.'],
    'three scores fold behind the timeout' => [false, ['performance' => 50, 'accessibility' => 100, 'seo' => 100],
        ['lighthouse.presentation.below', 'lighthouse.presentation.load_timed_out_continuation', 'lighthouse.presentation.below', 'lighthouse.presentation.below'],
        'Performance 34 (below 50), page loaded too slowly so scores may be incomplete, and 2 more.',
        'Performance below threshold, page loaded too slowly so scores may be incomplete.'],
    'drop without threshold' => [true, [],
        ['lighthouse.presentation.dropped', 'lighthouse.presentation.load_timed_out_continuation'],
        'Performance 34 (down 66 from 100), page loaded too slowly so scores may be incomplete.',
        'Performance dropped, page loaded too slowly so scores may be incomplete.'],
    'drop below threshold' => [true, ['performance' => 50],
        ['lighthouse.presentation.below_and_dropped', 'lighthouse.presentation.load_timed_out_continuation'],
        'Performance 34 (below 50, down 66 from 100), page loaded too slowly so scores may be incomplete.',
        'Performance below threshold, page loaded too slowly so scores may be incomplete.'],
]);

it('rejects a non-boolean load timeout flag at the renderer boundary', function (mixed $flag): void {
    lighthouseFailureHttp([...lighthouseFixture(), 'load_timed_out' => $flag]);

    $run = runLighthouse(lighthouseCheck());

    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe('Invalid Lighthouse load timeout flag.');
})->with(['string' => 'true', 'integer' => 1, 'null' => null, 'array' => [[]]]);

it('maps only the exact legacy 502 text at display time in UI and API', function (string $message, string $key): void {
    $user = User::factory()->owner()->create(['locale' => 'en']);
    lighthouseFailureHttp(['auditFailure' => ['code' => 'page_hung']]);
    $check = lighthouseCheck();
    $run = runLighthouse($check);
    $run->update(['summary' => 'causes.error', 'summary_params' => ['message' => $message], 'error' => json_encode(['class' => UnexpectedValueException::class, 'message' => $message], JSON_THROW_ON_ERROR)]);

    $this->actingAs($user)->get('/runs?check='.$check->id)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('runs.0.presentation.parts.0.key', $key));
    $token = $user->createToken('Lighthouse legacy display', ['checks:read', 'incidents:read'])->plainTextToken;
    $attempt = CheckRunAttempt::query()->where('check_id', $check->id)->sole();
    $this->withToken($token)->getJson('/api/runs/attempt:'.$attempt->id)
        ->assertJsonPath('result.headline_parts.reasons.0.key', $key);
    $incident = Incident::query()->where('check_id', $check->id)->sole();
    $this->withToken($token)->getJson('/api/incidents/'.$incident->id)
        ->assertJsonPath('data.current_evidence.summary.template', $key);
    expect($run->refresh()->summary)->toBe('causes.error');
    expect($run->summary_params)->toBe(['message' => $message]);
})->with([
    ['Lighthouse service returned HTTP 502.', 'causes.engine.lighthouse_incomplete'],
    ['Lighthouse service returned HTTP 503.', 'causes.error'],
    ['Lighthouse service returned HTTP 502. Extra', 'causes.error'],
]);

it('stores reached stage seconds and presents them only as ordered run details', function (): void {
    lighthouseFailureHttp(['auditFailure' => ['code' => 'audit_budget_exceeded', 'stage' => 'gathering', 'budgetSeconds' => 150,
        'stageSeconds' => ['browser_launch' => 3, 'navigation' => 47, 'gathering' => 100]]]);
    $check = lighthouseCheck();

    $run = runLighthouse($check);
    $presentation = app(CheckRunPresentations::class)->forRun($check, $run);

    expect($run->summary_params)->toHaveCount(5)->toMatchArray(['seconds' => 150, 'stage' => 'gathering',
        'stage_seconds_browser_launch' => 3, 'stage_seconds_navigation' => 47, 'stage_seconds_gathering' => 100]);
    expect($presentation->parts[0]->params)->toBe(['seconds' => 150]);
    expect(array_map(static fn (CheckRunPresentationPartData $part): string => $part->key, $presentation->details))->toBe([
        'lighthouse.stopped_during', 'lighthouse.stage_seconds', 'lighthouse.stage_seconds', 'lighthouse.stage_seconds',
    ]);
    expect($presentation->details[1]->labels)->toBe(['stage_label' => 'lighthouse.stage.browser_launch']);
    expect($presentation->details[1]->params)->toBe(['seconds' => 3]);
    expect($presentation->details[2]->labels)->toBe(['stage_label' => 'lighthouse.stage.navigation']);
    expect($presentation->details[2]->params)->toBe(['seconds' => 47]);
    expect($presentation->details[3]->labels)->toBe(['stage_label' => 'lighthouse.stage.gathering']);
    expect($presentation->details[3]->params)->toBe(['seconds' => 100]);
});
