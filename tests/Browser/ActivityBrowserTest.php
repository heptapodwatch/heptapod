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
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\Vite;

it('labels Activity failures by check cause and preserves historical errors and recovery', function (string $locale, array $labels): void {
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
                $rows[] = ['selector' => 'main div.grid:has(a[href="/sites/'.$site->id.'"]):has(time[datetime="'.$eventTime->toIso8601String().'"])', 'label' => array_shift($labels)];
            }
        }
    }

    $page = visit('/activity?period=all');
    foreach ($rows as $row) {
        $page->assertSeeIn($row['selector'].' [data-slot="badge"]', $row['label'])
            ->assertAttribute($row['selector'].' [data-slot="badge"]', 'aria-label', $row['label']);
    }
    $page->assertNoJavaScriptErrors();
})->with([
    'English' => ['en', ['Failed', 'Down', 'Check error', 'Recovered']],
    'German' => ['de', ['Fehlgeschlagen', 'Ausgefallen', 'Prüffehler', 'Wiederhergestellt']],
]);
