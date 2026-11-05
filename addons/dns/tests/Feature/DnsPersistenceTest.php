<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Dns\DnsPersistence;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunReference;
use Heptapod\Models\Check;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

function dnsPersistenceContext(Check $check, ?string $token = null, ?int $runId = null): CheckContext
{
    $at = CarbonImmutable::now();

    return new CheckContext($check->site_id, $check->id, $runId ?? (int) DB::scalar("SELECT nextval('check_runs_id_seq')"), $token ?? (string) Str::uuid(), $check->site->url, $at, $at->addSeconds(120), [], [], null);
}

it('fences DNS datasets and makes finalization atomic, idempotent and timestamp-exact', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Dns]);
    $context = dnsPersistenceContext($check);
    $other = dnsPersistenceContext($check, runId: $context->runId);
    $store = app(DnsPersistence::class);
    $row = ['dns_records' => [], 'raw_dns_records' => [], 'normalized_records' => [], 'issues' => [], 'authoritative_nameservers' => [], 'expected' => [], 'baseline_generation' => 0];
    $run = new RunReference($context->runId, $context->startedAt->setMicrosecond(123456));
    $store->finalize($run, $context->claimToken);
    $store->discard($context->runId, $context->claimToken);
    $store->stage($context, 'snapshot', [$row]);
    $store->stage($other, 'snapshot', [$row]);
    $store->finalize($run, (string) Str::uuid());
    expect(DB::table('dns_history_items')->count())->toBe(0);
    expect(fn () => DB::transaction(function () use ($store, $run, $context): void {
        $store->finalize($run, $context->claimToken);
        throw new RuntimeException('recorder rollback');
    }))->toThrow(RuntimeException::class, 'recorder rollback');
    expect($store->baseline($check->id))->toBeNull();
    expect(DB::table('dns_observations')->whereNotNull('run_ended_at')->count())->toBe(0);
    expect(DB::table('dns_history_items')->count())->toBe(0);

    $store->finalize($run, $other->claimToken);
    $store->finalize($run, $other->claimToken);
    $store->discard($context->runId, $context->claimToken);
    $store->discard($context->runId, $context->claimToken);
    $store->discard($context->runId, $other->claimToken);
    expect(DB::table('dns_observations')->count())->toBe(1);
    expect(DB::table('dns_observations')->value('run_ended_at'))->toContain('.123456');
    expect(DB::table('dns_history_items')->count())->toBe(1);
    expect($store->baseline($check->id)['run_id'])->toBe($run->id);
    expect(fn () => $store->stage($other, 'snapshot', [$row]))->toThrow(LogicException::class);
    expect(fn () => $store->finalize(new RunReference($run->id, $run->endedAt->addMicrosecond()), $other->claimToken))->toThrow(LogicException::class);
    expect(fn () => $store->stage($context, 'other', [$row]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $store->stage($context, 'snapshot', []))->toThrow(InvalidArgumentException::class);
});
