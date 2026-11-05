<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\RecordsReviewRuns;
use Heptapod\Actions\DisableCheck;
use Heptapod\Actions\PauseSite;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Checks\CheckEnablement;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Offline review data: only definitions are inserted; the engine owns every result and transition. */
final class ReviewStatesSeeder extends Seeder
{
    use RecordsReviewRuns;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Review fixtures are restricted to local and testing environments.');
        }
        $this->call(DatabaseSeeder::class);
        $originalHttp = app(OutboundHttp::class);
        $http = new FakeOutboundHttp;
        app()->instance(OutboundHttp::class, $http);
        try {
            DB::transaction(function () use ($http): void {
                $client = Client::query()->firstOrCreate(['slug' => 'wp5-review-fixture'], ['name' => 'Review all states', 'locale' => 'en', 'timezone' => 'UTC']);
                if (! $client->wasRecentlyCreated) {
                    return;
                }
                $now = CarbonImmutable::now('UTC');
                $registry = app(CheckRegistry::class);
                foreach (['down', 'failed', 'check-error', 'warning', 'pending', 'ok', 'maintenance', 'paused', 'disabled', 'recovered-tasks'] as $state) {
                    $key = in_array($state, ['failed', 'warning', 'recovered-tasks'], true) ? CheckKey::Cron : CheckKey::Uptime;
                    if (! in_array($key, $registry->keys(), true)) {
                        continue;
                    }
                    $site = Site::query()->create(['client_id' => $client->id, 'friendly_name' => 'Review '.$state,
                        'url' => 'https://review-'.$state.'.example', 'badge_id' => (string) Str::uuid()]);
                    $definition = $registry->get($key)->definition();
                    $check = Check::query()->create(['site_id' => $site->id, 'type' => $key, 'enabled' => $state === 'disabled',
                        'config' => $definition->configSchema->validateConfig([]), 'interval_seconds' => $definition->defaultInterval,
                        'failure_threshold' => $state === 'down' ? 2 : 1, 'next_run_at' => $now->addSeconds($definition->defaultInterval)]);
                    if ($state === 'disabled') {
                        app(DisableCheck::class)($check);

                        continue;
                    }
                    app(CheckEnablement::class)->enable($check, $definition);
                    if ($state === 'pending') {
                        continue;
                    }
                    if ($key === CheckKey::Cron) {
                        $created = $state === 'warning' ? $now->subMinutes(13) : $now->subMinutes(11);
                        $tasks = CarbonImmutable::withTestNow($created, fn (): array => array_map(fn (string $name): CronCheck => app(CronDefinitions::class)->create($check->id,
                            ['name' => $name, 'type' => 'simple', 'frequency_in_minutes' => 10, 'grace_time_in_minutes' => 5]), ['Backup', 'Cleanup']));
                        if ($state === 'recovered-tasks') {
                            $this->ping($tasks[0], $created);
                            $this->ping($tasks[1], $created);
                            $this->evaluate($check, $created);
                            $failed = $now->subMinutes(6);
                            foreach ($tasks as $task) {
                                $this->ping($task, $failed, 'failed');
                            }
                            $this->evaluate($check, $failed);
                            $this->ping($tasks[1], $failed->addMinutes(2));
                            $this->evaluate($check, $failed->addMinutes(2));
                            $this->ping($tasks[0], $failed->addSeconds(192));
                            $this->evaluate($check, $failed->addSeconds(192));
                        } else {
                            $at = $now->subMinutes(2);
                            $this->ping($tasks[0], $at, $state === 'failed' ? 'failed' : 'finished');
                            $this->ping($tasks[1], $at);
                            $this->evaluate($check, $at);
                        }

                        continue;
                    }
                    $http->respondUsing(fn (): FetchResult => new FetchResult(200, transfers: [['total_time' => 0.37]]));
                    $this->evaluate($check, $now->subMinutes(5));
                    if ($state === 'down') {
                        $http->respondUsing(fn (): FetchResult => new FetchResult(503, transfers: [['total_time' => 0.37]]));
                        foreach ([3, 2, 1] as $minutes) {
                            $this->evaluate($check, $now->subMinutes($minutes));
                        }
                    } elseif ($state === 'check-error') {
                        $this->evaluate($check, $now->subMinute(), new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null,
                            error: new CheckError(\RuntimeException::class, 'Review worker unavailable')));
                    } elseif ($state === 'maintenance') {
                        MaintenancePeriod::query()->create(['site_id' => $site->id, 'name' => 'Review maintenance', 'starts_at' => $now->subMinute(), 'ends_at' => $now->addDay()]);
                    } elseif ($state === 'paused') {
                        app(PauseSite::class)($site);
                    }
                }
            });
        } finally {
            app()->instance(OutboundHttp::class, $originalHttp);
        }
    }
}
