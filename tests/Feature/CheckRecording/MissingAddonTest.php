<?php

declare(strict_types=1);

use Heptapod\Addons\Uptime\UptimeCheck;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('records a missing addon without blocking healthy checks in the same dispatcher batch', function (): void {
    $registry = new CheckRegistry(app());
    $registry->register(UptimeCheck::class);
    app()->instance(CheckRegistry::class, $registry);
    expect($registry->keys())->not->toContain(CheckKey::Dns);
    $http = (new FakeOutboundHttp)->respondWith(new FetchRequest('https://healthy.test/', maxWireBytes: 10485760, maxDecodedBytes: 10485760, redirects: RedirectPolicy::Operator, timeoutSeconds: 30.0, acceptedStatuses: range(100, 599), strictRedirects: false), new FetchResult(200, 'ready'));
    app()->instance(OutboundHttp::class, $http);
    config(['database.redis.options.prefix' => 'missing_addon_'.Str::uuid().':']);
    Redis::purge('default');
    Redis::purge('cache');
    $missing = Check::factory()->create(['type' => CheckKey::Dns, 'next_run_at' => now()->subMinutes(2)]);
    $healthy = Check::factory()->create(['next_run_at' => now()->subMinute()]);
    $healthy->site->update(['url' => 'https://healthy.test/']);
    $sites = [$missing->site, $healthy->site];
    $heartbeatIds = DB::table('dispatcher_heartbeats')->pluck('id')->all();
    // Publish fixtures so the real after-commit queue dispatch can run.
    DB::commit();
    try {
        $this->artisan('heptapod:dispatch', ['--once' => true])->assertSuccessful();
        $run = CheckRun::query()->where('check_id', $missing->id)->sole();
        expect($run->result)->toBe(RunResult::Errored)->and($run->summary)->toBe('checks.engine.addon_missing')
            ->and($run->metrics)->toBeNull()
            ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'class' => LogicException::class, 'message' => 'Check addon is not installed.',
            ]);
        expect(Incident::query()->where('check_id', $missing->id)->sole()->cause)->toBe(IncidentCause::Errored)
            ->and(Event::query()->where('check_id', $missing->id)->orderBy('id')->pluck('type')->all())->toBe(['incident.opened', 'check.result_changed'])
            ->and($missing->fresh()->claim_token)->toBeNull()
            ->and($healthy->fresh()->claim_token)->not->toBeNull()
            ->and(DB::scalar('SELECT next_run_at > CURRENT_TIMESTAMP FROM checks WHERE id = ?', [$missing->id]))->toBeTrue();

        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'checks-fast', '--once' => true])->assertSuccessful();
        expect($healthy->fresh()->latest_run_result)->toBe(CheckResult::Succeeded)
            ->and($healthy->fresh()->claim_token)->toBeNull()
            ->and(CheckRun::query()->count())->toBe(2);
        $this->artisan('heptapod:dispatch', ['--once' => true])->assertSuccessful();
        expect(CheckRun::query()->count())->toBe(2)->and(Queue::connection('redis')->size('checks-fast'))->toBe(0);
        expect($http->requests)->toHaveCount(1);
    } finally {
        Queue::connection('redis')->clear('checks-fast');
        foreach ($sites as $site) {
            $site->delete();
            DB::table('clients')->where('id', $site->client_id)->delete();
        }
        DB::table('dispatcher_heartbeats')->whereNotIn('id', $heartbeatIds)->delete();
        DB::beginTransaction();
    }
});
