<?php

declare(strict_types=1);

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunPhase;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\User;
use Heptapod\Queries\CheckRunsReader;
use Heptapod\Queries\RunsReader;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('keeps a manual crawl behind two long crawls queued and visible after the old execution lease expires', function (): void {
    $prefix = 'queue-lease-'.Str::uuid().':';
    config(['database.redis.options.prefix' => $prefix, 'cache.default' => 'redis', 'cache.prefix' => $prefix]);
    Redis::purge('default');
    Redis::purge('cache');
    $queue = Queue::connection('redis');
    expect(config()->integer('horizon.defaults.checks-heavy.minProcesses'))->toBe(config()->integer('horizon.defaults.checks-heavy.maxProcesses'));
    $checks = Check::factory()->count(3)->create(['type' => CheckKey::BrokenLinks,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['max_depth' => 0, 'max_pages' => 1, 'respect_robots' => false]),
        'next_run_at' => now()->addHour()]);
    $tokens = [];
    foreach ($checks as $check) {
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        $tokens[$check->id] = $check->refresh()->claim_token;
    }
    $viewer = User::factory()->create();
    $viewer->assignRole('Owner');
    DB::commit();
    try {
        expect($queue->size('checks-crawl'))->toBe(3);
        $waiting = $checks->last();
        $requestedAt = now()->subMinutes(21);
        DB::table('check_run_attempts')->where('check_id', $waiting->id)->update(['requested_at' => $requestedAt, 'due_at' => $requestedAt]);
        $waiting->refresh()->load('site');
        $state = app(CheckRunsReader::class)->state($waiting);
        expect($state->phase)->toBe(RunPhase::Queued)->and($state->queue_position)->toBe(3);
        $row = collect(app(RunsReader::class)->history($viewer)->toArray()['runs'])->firstWhere('check_id', $waiting->id);
        expect($row['run']['phase'])->toBe('queued')->and($row['run']['queue_position'])->toBe(3)
            ->and($row['requested_at'])->toBe($requestedAt->toIso8601String());
        DB::table('checks')->whereIn('id', $checks->modelKeys())->update([
            'dispatched_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1300 seconds'"),
            'lease_expires_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'"),
        ]);
        expect(ExecutionBudget::lease(ExecutionBudget::MaximumTimeout))->toBeLessThan(1300)
            ->and(app(ClaimService::class)->dispatchDue())->toBe(0)
            ->and($queue->size('checks-crawl'))->toBe(3);
        foreach ($checks as $check) {
            expect($check->refresh()->claim_token)->toBe($tokens[$check->id])
                ->and($check->lease_expires_at->isFuture())->toBeTrue();
        }
        DB::table('checks')->where('id', $checks->first()->id)->update(['lease_expires_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
        expect(app(ClaimService::class)->runNow($checks->first()->id, RunOrigin::System))->toBeNull()
            ->and($checks->first()->refresh()->claim_token)->toBe($tokens[$checks->first()->id]);
        app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request, CheckContext $context): FetchResult {
            $running = Check::query()->findOrFail($context->checkId);
            expect($running->lease_expires_at->diffInSeconds($context->startedAt))->toBe((float) ExecutionBudget::runningLease(1200));

            return new FetchResult(200, '<html>OK</html>', ['Content-Type' => ['text/html']]);
        }));
        foreach ($checks as $check) {
            $job = $queue->pop('checks-crawl');
            expect($job)->not->toBeNull();
            $job->fire();
            $job->delete();
            expect($check->refresh()->claim_token)->toBeNull();
        }
        expect($queue->size('checks-crawl'))->toBe(0)
            ->and(CheckRunAttempt::query()->count())->toBe(3)
            ->and(CheckRun::query()->count())->toBe(3);
        $lost = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
        $checks->push($lost);
        expect(app(ClaimService::class)->runNow($lost->id, RunOrigin::System))->not->toBeNull();
        $oldToken = $lost->refresh()->claim_token;
        $queue->clear('checks-crawl');
        DB::table('checks')->where('id', $lost->id)->update(['lease_expires_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
        expect(app(ClaimService::class)->dispatchDue())->toBe(1)
            ->and($lost->refresh()->claim_token)->not->toBe($oldToken)
            ->and($queue->size('checks-crawl'))->toBe(1)
            ->and(CheckRunAttempt::query()->where('claim_token', $oldToken)->sole()->phase)->toBe(RunPhase::Skipped);
    } finally {
        $queue->clear('checks-crawl');
        $viewer->delete();
        foreach ($checks as $check) {
            $site = $check->site;
            $client = $site->client;
            $check->delete();
            $site->delete();
            $client->delete();
        }
        DB::beginTransaction();
    }
});

it('renews a present fast job for 600 seconds and promptly reclaims its lost job', function (): void {
    $prefix = 'fast-queue-lease-'.Str::uuid().':';
    config(['database.redis.options.prefix' => $prefix, 'cache.default' => 'redis', 'cache.prefix' => $prefix]);
    Redis::purge('default');
    Redis::purge('cache');
    $queue = Queue::connection('redis');
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'next_run_at' => now()->addHour()]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $token = $check->refresh()->claim_token;
    DB::commit();
    try {
        DB::table('checks')->where('id', $check->id)->update(['lease_expires_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
        expect(app(ClaimService::class)->dispatchDue())->toBe(0)
            ->and($check->refresh()->claim_token)->toBe($token)
            ->and($check->lease_expires_at->diffInSeconds(now(), true))->toBeBetween(599, 601);
        $queue->clear('checks-fast');
        DB::table('checks')->where('id', $check->id)->update(['lease_expires_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
        expect(app(ClaimService::class)->dispatchDue())->toBe(1)
            ->and($check->refresh()->claim_token)->not->toBe($token);
    } finally {
        $queue->clear('checks-fast');
        $site = $check->site;
        $client = $site->client;
        $check->delete();
        $site->delete();
        $client->delete();
        DB::beginTransaction();
    }
});
