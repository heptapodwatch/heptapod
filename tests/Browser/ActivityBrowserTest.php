<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Heptapod\Addons\Crawl\CrawlMetrics;
use Heptapod\Addons\Uptime\UptimeEvidence;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\Vite;

it('labels Activity failures by check cause and merges a check error with its recovery', function (string $locale, array $labels, string $merged): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->freezeTime();
    $this->seed(DatabaseSeeder::class);
    $owner = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole();
    $owner->update(['locale' => $locale]);
    $this->actingAs($owner);
    $at = CarbonImmutable::now()->subMinutes(5)->startOfSecond();
    $rows = [];
    foreach ([CheckKey::BrokenLinks, CheckKey::Uptime] as $key) {
        $site = Site::factory()->create(['friendly_name' => 'Activity '.$key->value]);
        $check = Check::factory()->for($site)->create(['type' => $key]);
        $results = $key === CheckKey::Uptime
            ? [RunResult::Succeeded, RunResult::Failed, RunResult::Failed, RunResult::Errored, RunResult::Succeeded]
            : [RunResult::Succeeded, RunResult::Failed, RunResult::Failed];
        foreach ($results as $offset => $result) {
            $ended = $at->addSeconds($offset * 60);
            $claim = ClaimedRun::start($check->refresh(), $ended->subSecond());
            $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
            $outcome = $result === RunResult::Errored
                ? new CheckOutcome($result, 'checks.engine.errored', [], null, error: new CheckError(RuntimeException::class, 'Probe deadline exceeded'))
                : new CheckOutcome($result, $key === CheckKey::Uptime ? 'uptime.'.$result->value : 'events.failed', [],
                    $key === CheckKey::Uptime ? new UptimeMetrics(null, null, null, null, null, 0.2, $curl, $ended->toIso8601String()) : new CrawlMetrics(1, 1, 0, $result === RunResult::Failed ? 1 : 0, 0, 0),
                    $key === CheckKey::Uptime ? new UptimeEvidence($result === RunResult::Failed ? 503 : 200, $result === RunResult::Failed ? ['status'] : []) : null);
            app(ResultRecorder::class)->record(new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, $ended, $outcome));
            if ($offset >= 2) {
                $eventTime = $offset === 2 && $key === CheckKey::BrokenLinks ? $at->addMinute() : $ended;
                $rows[] = ['selector' => '[data-activity-timeline] li:has(a[href="/sites/'.$site->id.'"]):has(time[datetime="'.$eventTime->toIso8601String().'"])', 'label' => array_shift($labels)];
            }
        }
    }

    // The check error and the recovery that ends it are one timeline row: the recovery's ring, the error as its lead.
    [$failed, $down, $checkError, $recovered] = $rows;
    $page = visit('/activity?period=all');
    foreach ([$failed, $down, $recovered] as $row) {
        $page->assertAttribute($row['selector'].' > span > .status-ring', 'aria-label', $row['label']);
    }
    $page->assertSeeIn($recovered['selector'], $merged)
        ->assertNotPresent($checkError['selector'])
        ->assertNoJavaScriptErrors();
})->with([
    'English' => ['en', ['Failed', 'Down', 'Check error', 'Recovered'], 'Check error →'],
    'German' => ['de', ['Fehlgeschlagen', 'Ausgefallen', 'Prüffehler', 'Wiederhergestellt'], 'Prüffehler →'],
]);

it('cancels an older batch still loading when the reader applies new changes, so the next load joins the fresh feed', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->freezeTime();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $site = Site::factory()->create(['friendly_name' => 'Batched activity']);
    // Four checks, each changing state every three hours, staggered: one group per change, no pairs, about 220 groups.
    $checks = [];
    foreach ([CheckKey::Dns, CheckKey::Sitemap, CheckKey::BrokenLinks, CheckKey::MixedContent] as $offset => $key) {
        $checks[] = $check = Check::factory()->for($site)->create(['type' => $key]);
        $failed = false;
        for ($at = now()->subDays(6)->addMinutes(45 * $offset); $at->lessThan(now()->subMinutes(30)); $at = $at->addHours(3)) {
            Event::factory()->for($check)->create(['occurred_at' => $at,
                'from_result' => $failed ? CheckResult::Failed : CheckResult::Succeeded, 'to_result' => $failed ? CheckResult::Succeeded : CheckResult::Failed]);
            $failed = ! $failed;
        }
    }
    $page = visit('/activity?period=7d');
    // Hold every older-batch request in flight until released; aborting a held request fails it like a real abort.
    $page->script(<<<'JS'
        (() => {
            const { open, send, abort } = XMLHttpRequest.prototype;
            window.__held = [];
            XMLHttpRequest.prototype.open = function (method, url, ...rest) { this.__url = String(url); return open.call(this, method, url, ...rest); };
            XMLHttpRequest.prototype.send = function (body) {
                if (!this.__url.includes('before=') || window.__released) return send.call(this, body);
                this.__held = true;
                window.__held.push(() => { if (!this.__aborted) send.call(this, body); });
            };
            XMLHttpRequest.prototype.abort = function () {
                if (!this.__held) return abort.call(this);
                this.__aborted = true;
                this.onabort?.(new ProgressEvent('abort'));
            };
            window.__release = () => { window.__released = true; window.__held.splice(0).forEach((resume) => resume()); };
        })()
        JS);
    $rows = fn (): array => $page->script('[...document.querySelectorAll("[data-activity-timeline] li")].map((row) => row.querySelector("time").getAttribute("datetime"))');
    expect($rows())->toHaveCount(50);

    $page->script('window.scrollTo(0, document.body.scrollHeight)');
    $page->wait(1);
    expect($page->script('window.__held.length'))->toBe(1);

    Event::factory()->for($checks[0])->create(['occurred_at' => now(), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $page->script('document.dispatchEvent(new Event("visibilitychange"))');
    $page->wait(1)->assertSee('1 new change ↑');
    $page->click('button:has-text("1 new change ↑")');
    $page->wait(1);
    $page->script('window.__release()');
    $page->wait(1);
    $page->script('window.scrollTo(0, document.body.scrollHeight)');
    $page->wait(2);

    // The fresh first batch and the next one, in order: the newest 100 changes without a gap or a repeat.
    $expected = Event::query()->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->pluck('occurred_at')
        ->map(fn (CarbonImmutable $at): string => $at->toIso8601String())->all();
    expect($rows())->toBe($expected);
    $page->assertNoJavaScriptErrors();
});
