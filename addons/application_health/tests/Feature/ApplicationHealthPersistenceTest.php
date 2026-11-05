<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunReference;
use Heptapod\Models\Event;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Pest.php';

it('isolates attempts and makes publication transactional and idempotent', function (): void {
    $check = applicationHealthCheck();
    respondApplicationHealth($check, applicationHealthPayload());
    $type = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth);
    $first = applicationHealthContext($check);
    $other = applicationHealthContext($check, $first->runId);
    $type->execute($first);
    $type->execute($other);
    $run = new RunReference($first->runId, CarbonImmutable::parse('2026-10-01T12:00:00.123456Z'));
    $store = $type->persistence();
    $store->finalize($run, (string) Str::uuid());
    expect(DB::table('application_health_components')->value('snapshot'))->toBeNull();
    expect(function () use ($store, $run, $first): void {
        DB::transaction(function () use ($store, $run, $first): void {
            $store->finalize($run, $first->claimToken);
            throw new RuntimeException('recorder rollback');
        });
    })->toThrow(RuntimeException::class, 'recorder rollback');
    expect(DB::table('application_health_components')->value('snapshot'))->toBeNull()
        ->and(DB::table('application_health_snapshots')->whereNotNull('run_ended_at')->count())->toBe(0);

    $store->finalize($run, $other->claimToken);
    $store->finalize($run, $other->claimToken);
    $store->discard($first->runId, $first->claimToken);
    $store->discard($first->runId, $first->claimToken);
    $store->discard($first->runId, $other->claimToken);

    expect(DB::table('application_health_snapshots')->sole()->claim_token)->toBe($other->claimToken)
        ->and(DB::table('application_health_snapshots')->value('run_ended_at'))->toContain('.123456')
        ->and(DB::table('application_health_components')->value('active'))->toBeTrue();
    expect(fn () => $type->execute($other))->toThrow(LogicException::class, 'Cannot stage an accepted');
    expect(fn () => $store->finalize(new RunReference($run->id, $run->endedAt->addSecond()), $other->claimToken))->toThrow(LogicException::class, 'different timestamp');
    $absent = new RunReference($run->id + 1, $run->endedAt);
    $store->finalize($absent, $first->claimToken);
    $store->discard($absent->id, $first->claimToken);
    expect(DB::table('application_health_snapshots')->count())->toBe(1);
});

it('rejects foreign components and unsupported or malformed staging datasets', function (): void {
    $check = applicationHealthCheck();
    respondApplicationHealth($check, applicationHealthPayload());
    $context = applicationHealthContext($check);
    $type = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth);
    $type->execute($context);
    $rows = json_decode(DB::table('application_health_snapshots')->value('components'), true, flags: JSON_THROW_ON_ERROR);
    $other = applicationHealthContext(applicationHealthCheck());

    expect(fn () => $type->persistence()->stage($other, 'components', [['components' => $rows]]))->toThrow(InvalidArgumentException::class, 'another check');
    expect(fn () => $type->persistence()->stage($context, 'other', [[]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $type->persistence()->stage($context, 'components', []))->toThrow(InvalidArgumentException::class);
    expect(DB::table('application_health_snapshots')->count())->toBe(1);
});

it('fences stale recordings and deduplicates retried accepted change events', function (): void {
    $check = applicationHealthCheck();
    respondApplicationHealth($check, applicationHealthPayload('warning', 'Disk usage at 90%'));
    runApplicationHealth($check);
    respondApplicationHealth($check, applicationHealthPayload('warning', 'Disk usage at 91%'));
    $type = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth);
    $stale = applicationHealthContext($check);
    $staleOutcome = $type->execute($stale);
    $current = ClaimedRun::start($check);
    $recorder = app(TransactionalResultRecorder::class);
    expect($recorder->record(new RecordableRun($check->id, $stale->claimToken, $stale->startedAt, $stale->startedAt->addSecond(), $staleOutcome))->accepted)->toBeFalse();
    $type->persistence()->discard($stale->runId, $stale->claimToken);
    $outcome = $type->execute($current);
    $repeat = $type->execute($current);
    expect($repeat->events[0]->dedupeKey)->toBe($outcome->events[0]->dedupeKey);
    $run = new RecordableRun($check->id, $current->claimToken, $current->startedAt, $current->startedAt->addSecond(), $outcome);

    expect($recorder->record($run)->accepted)->toBeTrue()
        ->and($recorder->record($run)->accepted)->toBeFalse()
        ->and(Event::query()->where('type', 'application_health.component_changed')->count())->toBe(1)
        ->and(DB::table('application_health_components')->value('run_id'))->toBe($current->runId)
        ->and(DB::table('application_health_snapshots')->count())->toBe(2);
});
