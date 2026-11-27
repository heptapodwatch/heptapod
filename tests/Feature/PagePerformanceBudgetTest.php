<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\CheckRunRollupHourly;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $this->withoutVite();
});

function seedPagePerformanceSites(int $from, int $until, Client $client): void
{
    for ($id = $from; $id <= $until; $id++) {
        $site = Site::factory()->for($client)->create([
            'id' => $id, 'url' => "https://site-{$id}.example.test", 'friendly_name' => "Site {$id}",
        ]);
        foreach ([CheckKey::Uptime, CheckKey::Dns, CheckKey::CertificateHealth] as $offset => $type) {
            $check = Check::factory()->for($site)->create([
                'id' => ($id - 1) * 3 + $offset + 1, 'type' => $type,
                'next_run_at' => now()->addMinute(), 'latest_run_result' => CheckResult::Failed,
                'latest_run_ended_at' => now()->subMinute(),
            ]);
            Incident::factory()->for($check)->create();
            CheckRun::factory()->for($check)->create(['metrics' => ['total_time_in_seconds' => 0.125]]);
            // Activity and the Runs history read these: one finished attempt and a failure/recovery pair per check.
            CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => Str::uuid()->toString(),
                'phase' => 'done', 'queue' => 'checks-fast', 'requested_at' => now()->subHour(), 'finished_at' => now()->subMinutes(59)]);
            Event::factory()->for($check)->create(['occurred_at' => now()->subHours(5)->subMinutes($id), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed]);
            Event::factory()->for($check)->create(['occurred_at' => now()->subHours(2)->subMinutes($id), 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded]);
            if ($type === CheckKey::Uptime) {
                for ($hour = 1; $hour <= 24; $hour++) {
                    CheckRunRollupHourly::factory()->for($check)->create([
                        'bucket_start' => now()->subHours($hour), 'succeeded_count' => 60,
                        'metric_sums' => ['total_time_in_seconds' => 7.5],
                        'metric_counts' => ['total_time_in_seconds' => 60],
                        'metric_p95' => ['total_time_in_seconds' => 0.125],
                    ]);
                }
            }
        }
    }
}

it('keeps page query counts constant from three to thirty sites', function (string $path, string $component, string $props): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create(['name' => 'Performance client']);
    seedPagePerformanceSites(1, 3, $client);
    $headers = [];
    if ($props !== '') {
        $headers += ['X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $props];
    }
    $counts = [];
    foreach ([3, 30] as $size) {
        if ($size === 30) {
            seedPagePerformanceSites(4, 30, $client);
        }
        // Warm request-independent settings/permission caches equally, then measure with fresh auth relations.
        $this->actingAs($owner->fresh())->get($path, $headers)->assertOk();
        $this->actingAs($owner->fresh());
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->get($path, $headers);
            $counts[] = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $response->assertOk();
        expect($response->inertiaPage()['component'])->toBe($component);
    }

    expect($counts[1])->toBe($counts[0], "$path [$props]: {$counts[0]} queries for 3 sites; {$counts[1]} for 30");
})->with([
    'sites initial' => ['/sites', 'sites/index', ''],
    'sites list' => ['/sites', 'sites/index', 'sites'],
    'sites history' => ['/sites', 'sites/index', 'sites_history'],
    'site overview' => ['/sites/1', 'sites/show', ''],
    'check detail' => ['/sites/1/checks/1', 'sites/show', ''],
    'incidents list' => ['/incidents', 'incidents/index', ''],
    'sites list, 100 per page by client' => ['/sites?per_page=100&sort=client', 'sites/index', 'sites'],
    'sites history, 100 per page' => ['/sites?per_page=100', 'sites/index', 'sites_history'],
    'incidents list, 100 per page' => ['/incidents?per_page=100', 'incidents/index', ''],
    'clients list, 100 per page' => ['/clients?per_page=100', 'clients/index', ''],
    'runs, 100 per page' => ['/runs?per_page=100', 'runs/index', ''],
    'activity' => ['/activity?period=7d', 'activity/index', ''],
    'activity live head' => ['/activity?period=7d', 'activity/index', 'head'],
    'runs history, 100 per page' => ['/runs?per_page=100', 'runs/index', 'runs,next_cursor'],
]);

it('keeps fixed-seed page props within the JSON payload budget', function (string $path, string $component, string $props, int $budget): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create(['name' => 'Performance client']);
    seedPagePerformanceSites(1, 30, $client);

    $response = $this->actingAs($owner)->get($path, [
        'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $props,
    ])->assertOk();
    expect($response->inertiaPage()['component'])->toBe($component);
    $payload = $response->inertiaProps();
    foreach (explode(',', $props) as $prop) {
        expect($payload)->toHaveKey($prop);
    }

    expect(strlen(json_encode($payload, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual($budget, "$path [$props]");
})->with([
    // Measured bytes for this seed: 70,005 / 15,677 / 21,335; allow about 20% growth.
    'sites list' => ['/sites', 'sites/index', 'sites', 84_100],
    'sites history' => ['/sites', 'sites/index', 'sites_history', 18_900],
    'site overview' => ['/sites/1', 'sites/show', 'site,header,history,open_uptime_incident,client,run_all_checks,can_manage,uptime_ranges,run_all_result,maintenance,response_ms,alert_channels,lighthouse,can_move,resume_at,repositories', 25_700],
]);
