<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckSchedule;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunPhase;
use Heptapod\Events\LiveSignal;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\DispatcherHeartbeat;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ClaimService
{
    private const int OverdueGapSeconds = 300;

    private const int MaximumCatchUpWindowSeconds = 900;

    private const int FastCooldownSeconds = 30;

    private const int HeavyCooldownSeconds = 300;

    /** Outstanding manual attempts per heavy queue, so manual work cannot crowd out scheduled monitoring. */
    private const array ManualHeavyOutstanding = ['checks-crawl' => 2, 'checks-browser' => 1];

    public function __construct(private CheckRegistry $registry, private ResultRecorder $recorder, private DatabaseClock $clock) {}

    public function dispatchDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Dispatcher batch must contain 1..1000 checks.');
        }

        return DB::transaction(function () use ($limit): int {
            $at = $this->clock->now();
            $atText = $at->format('Y-m-d H:i:s.uP');
            $heartbeatQuery = DispatcherHeartbeat::query();
            $heartbeatQuery->getQuery()->orderByDesc('last_seen_at');
            $lastHeartbeat = $heartbeatQuery->first()?->last_seen_at;
            // A first start has no recorded gap; stale schedules alone can also come from pause/disable.
            if ($lastHeartbeat !== null && $lastHeartbeat < $at->subSeconds(self::OverdueGapSeconds)) {
                $this->reSpreadOverdue($at);
            }

            $query = $this->eligible();
            $query->getQuery()->where(function (Builder $query) use ($atText): void {
                $query->where(fn (Builder $due) => $due->where('checks.next_run_at', '<=', $atText)->whereNull('checks.lease_expires_at'))
                    ->orWhere(fn (Builder $expired) => $expired->whereNotNull('checks.claim_token')->where('checks.lease_expires_at', '<', $atText));
            })->orderByRaw('CASE WHEN checks.type = ? THEN 0 ELSE 1 END', [CheckKey::Uptime->value])
                ->orderBy('checks.next_run_at')->orderBy('checks.id')->limit($limit)
                ->lock('FOR UPDATE OF checks SKIP LOCKED');
            $checks = $query->get();

            $claimed = 0;
            foreach ($checks as $check) {
                if ($this->queuedJobStillExists($check)) {
                    $this->extendQueuedLease($check, $at);

                    continue;
                }
                $this->claim($check, $at);
                $claimed++;
            }

            return $claimed;
        });
    }

    private function reSpreadOverdue(CarbonImmutable $at): void
    {
        /** @var int|null $maxId */
        $maxId = DB::table('checks')->max('id');
        $lastId = 0;
        $reSpread = 0;
        $maximumWindow = 0;
        do {
            $query = $this->eligible();
            // A fixed ID frontier bounds the catch-up while other processes add checks.
            $query->getQuery()->where('checks.id', '>', $lastId)->where('checks.id', '<=', $maxId ?? 0)
                ->where('checks.type', '!=', CheckKey::Uptime->value)
                ->whereNull('checks.claim_token')->whereNull('checks.lease_expires_at')->where('checks.manual_run', false)
                ->where('checks.next_run_at', '<', $at->subSeconds(self::OverdueGapSeconds))
                ->orderBy('checks.id')->limit(500)->lock('FOR UPDATE OF checks SKIP LOCKED');
            $checks = $query->get();
            foreach ($checks as $check) {
                $window = min($check->interval_seconds, self::MaximumCatchUpWindowSeconds);
                $next = CheckSchedule::nextSlot($check->id, $window, $at);
                DB::table('checks')->where('id', $check->id)->update(['next_run_at' => $next->format('Y-m-d H:i:s.uP')]);
                $lastId = $check->id;
                $reSpread++;
                $maximumWindow = max($maximumWindow, $window);
            }
        } while ($checks->count() === 500);

        if ($reSpread > 0) {
            DB::afterCommit(static fn () => Log::info('Re-spread overdue checks after a dispatcher gap.', [
                'checks' => $reSpread, 'window_seconds' => $maximumWindow, 'gap_threshold_seconds' => self::OverdueGapSeconds,
            ]));
        }
    }

    /** Manual requests share scheduled ownership, enablement and pause rules.
     * @param  list<array{name: string, value: string}>  $headers
     * @param  bool  $heavyAdmission  manual heavy-queue admission; the compatibility request-run route opts out
     *
     * @throws ManualRunRefused
     */
    public function runNow(int $checkId, RunOrigin $origin, array $headers = [], ?int $actorUserId = null, ?int $actorTokenId = null, bool $heavyAdmission = true): ?CheckRunAttempt
    {
        return DB::transaction(function () use ($checkId, $origin, $headers, $actorUserId, $actorTokenId, $heavyAdmission): ?CheckRunAttempt {
            $queue = $origin === RunOrigin::Manual ? $this->queueOf($checkId) : null;
            if ($heavyAdmission && $queue !== null && $queue !== 'checks-fast') {
                // Serializes manual heavy admission per queue; the scheduler never takes this lock.
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['manual-admission:'.$queue]);
                $outstanding = CheckRunAttempt::query()->getQuery()->where('queue', $queue)->where('origin', RunOrigin::Manual->value)
                    ->whereIn('phase', [RunPhase::Queued->value, RunPhase::Running->value])->count();
                if ($outstanding >= (self::ManualHeavyOutstanding[$queue] ?? throw new \LogicException('Unknown heavy queue.'))) {
                    throw new ManualRunRefused('queue_admission_limited', 60);
                }
            }
            $at = $this->clock->now();
            $atText = $at->format('Y-m-d H:i:s.uP');
            $query = $this->eligible();
            $query->getQuery()->where('checks.id', $checkId)
                ->where(fn (Builder $query) => $query->whereNull('checks.lease_expires_at')->orWhere('checks.lease_expires_at', '<', $atText))
                ->lock('FOR UPDATE OF checks SKIP LOCKED');
            $check = $query->first();
            if (! $check instanceof Check) {
                return null;
            }
            if ($queue !== null) {
                $cooldown = $queue === 'checks-fast' ? self::FastCooldownSeconds : self::HeavyCooldownSeconds;
                /** @var int|float|string|null $elapsed */
                $elapsed = DB::scalar('SELECT EXTRACT(EPOCH FROM ?::timestamptz - last_manual_requested_at) FROM checks WHERE id = ?', [$atText, $checkId]);
                if ($elapsed !== null && (float) $elapsed < $cooldown) {
                    throw new ManualRunRefused('cooldown', max(1, (int) ceil($cooldown - (float) $elapsed)));
                }
            }
            if ($this->queuedJobStillExists($check)) {
                $this->extendQueuedLease($check, $at);

                return null;
            }
            if ($queue !== null) {
                DB::update('UPDATE checks SET last_manual_requested_at = ? WHERE id = ?', [$atText, $checkId]);
            }

            return $this->claim($check, $at, $headers, $actorUserId, true, $origin, $actorTokenId);
        });
    }

    private function queueOf(int $checkId): string
    {
        $type = Check::query()->findOrFail($checkId)->type;

        return in_array($type, $this->registry->keys(), true) ? $this->registry->get($type)->definition()->queue : 'checks-fast';
    }

    public function revoke(Check $check): void
    {
        DB::transaction(function () use ($check): void {
            $query = Check::query()->whereKey($check->id);
            $query->getQuery()->lockForUpdate();
            $current = $query->firstOrFail();
            if ($current->active_run_id !== null && $current->claim_token !== null && in_array($current->type, $this->registry->keys(), true)) {
                $this->registry->get($current->type)->persistence()?->discard($current->active_run_id, $current->claim_token);
            }
            $attempt = CheckRunAttempt::query()->with('actor')->where('claim_token', $current->claim_token)->first();
            if ($attempt instanceof CheckRunAttempt && in_array($attempt->phase, [RunPhase::Queued, RunPhase::Running], true)) {
                $attempt->update(['phase' => RunPhase::Skipped, 'finished_at' => now(), 'note' => 'revoked']);
                LiveSignal::forRun('run.finished', $current->site()->getQuery()->firstOrFail(), $current->id,
                    $attempt->started_at, $attempt->actor?->name, outcome: RunPhase::Skipped, attemptId: $attempt->id);
            }
            $current->update(['awaiting_result_since' => null, 'lost_attempts' => 0, 'last_loss_started_at' => null, 'claim_token' => null, 'dispatched_at' => null, 'lease_expires_at' => null,
                'run_started_at' => null, 'active_run_id' => null, 'run_headers' => null, 'manual_run' => false, 'next_run_at' => now()]);
        });
    }

    private function extendQueuedLease(Check $check, CarbonImmutable $at): void
    {
        $attempt = CheckRunAttempt::query()->where('claim_token', $check->claim_token)->firstOrFail();
        DB::update('UPDATE checks SET lease_expires_at = ? WHERE id = ?',
            [$at->addSeconds(ExecutionBudget::queuedStaleTimeout($attempt->queue))->format('Y-m-d H:i:s.uP'), $check->id]);
    }

    private function queuedJobStillExists(Check $check): bool
    {
        if ($check->claim_token === null || $check->run_started_at !== null) {
            return false;
        }
        $attempt = CheckRunAttempt::query()->where('claim_token', $check->claim_token)->first();
        if (! $attempt instanceof CheckRunAttempt || $attempt->phase !== RunPhase::Queued) {
            return false;
        }
        $queue = Queue::connection('redis');
        if (! $queue instanceof RedisQueue) {
            throw new \LogicException('Queued claim reconciliation requires a Redis queue.');
        }
        $key = $queue->getQueue($attempt->queue);
        // Laravel moves a job atomically from pending to reserved; scan all three states before reclaiming its claim.
        foreach ([$key, $key.':reserved', $key.':delayed'] as $index => $redisKey) {
            $jobs = $queue->getConnection()->command($index === 0 ? 'lrange' : 'zrange', [$redisKey, 0, -1]);
            if (! is_array($jobs)) {
                throw new \UnexpectedValueException('Queued job scan returned an invalid Redis response.');
            }
            foreach ($jobs as $payload) {
                if (! is_string($payload)) {
                    throw new \UnexpectedValueException('Queued job payload must be a string.');
                }
                if (str_contains($payload, $check->claim_token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return EloquentBuilder<Check> */
    private function eligible(): EloquentBuilder
    {
        $query = Check::query();
        $query->getQuery()->whereNotIn('checks.type', $this->registry->unavailableKeys())->select('checks.*')->join('sites', 'sites.id', '=', 'checks.site_id')
            ->where('checks.enabled', true)->whereNull('sites.paused_at')->whereNull('sites.marked_for_deletion_at');

        return $query;
    }

    /** @param list<array{name: string, value: string}> $headers */
    private function claim(Check $check, CarbonImmutable $at, array $headers = [], ?int $actorUserId = null, bool $manual = false,
        RunOrigin $origin = RunOrigin::Scheduled, ?int $actorTokenId = null): CheckRunAttempt
    {
        $checkId = $check->id;
        $type = in_array($check->type, $this->registry->keys(), true) ? $this->registry->get($check->type) : null;
        if ($check->active_run_id !== null && $check->claim_token !== null) {
            $type?->persistence()?->discard($check->active_run_id, $check->claim_token);
        }
        $lost = 0;
        $lastLossStartedAt = $check->last_loss_started_at;
        if ($check->claim_token !== null) {
            $replaced = CheckRunAttempt::query()->with('actor')->where('claim_token', $check->claim_token)->first();
            if ($replaced instanceof CheckRunAttempt && in_array($replaced->phase, [RunPhase::Queued, RunPhase::Running], true)) {
                $lost = 1;
                $lastLossStartedAt = $replaced->started_at;
                $replaced->update(['phase' => RunPhase::Skipped, 'finished_at' => now(), 'note' => 'lease_replaced']);
                LiveSignal::forRun('run.finished', $check->site()->getQuery()->firstOrFail(), $check->id,
                    $replaced->started_at, $replaced->actor?->name, outcome: RunPhase::Skipped, attemptId: $replaced->id);
            }
        }
        $definition = $type?->definition();
        $lease = ExecutionBudget::queuedLease($definition->timeout ?? ExecutionBudget::MaximumTimeout, $definition->queue ?? 'checks-fast');
        $token = (string) Str::uuid();
        $nextRunAt = $check->next_run_at <= $at
            ? CheckSchedule::nextSlot($checkId, $check->interval_seconds, $at)
            : $check->next_run_at;
        // Freshness follows app-clock results; schedule slots and leases retain PostgreSQL's shared clock.
        DB::update(<<<'SQL'
            UPDATE checks SET awaiting_result_since = COALESCE(awaiting_result_since, ?),
                lost_attempts = lost_attempts + ?, last_loss_started_at = ?, claim_token = ?, dispatched_at = ?,
                lease_expires_at = ?, manual_run = ?,
                run_started_at = NULL, active_run_id = NULL,
                next_run_at = ?,
                updated_at = ?
            WHERE id = ?
            SQL, [CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP'), $lost, $lastLossStartedAt?->format('Y-m-d H:i:s.uP'), $token,
            $at->format('Y-m-d H:i:s.uP'), $at->addSeconds($lease)->format('Y-m-d H:i:s.uP'), $manual,
            $nextRunAt->format('Y-m-d H:i:s.uP'), CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP'), $checkId]);

        $check->update(['run_headers' => $headers === [] ? null : $headers]);
        $attempt = CheckRunAttempt::query()->create(['check_id' => $checkId, 'claim_token' => $token, 'phase' => RunPhase::Queued,
            'queue' => $definition->queue ?? 'checks-fast', 'actor_user_id' => $actorUserId, 'actor_token_id' => $actorTokenId, 'origin' => $origin->value, 'requested_at' => now(),
            'due_at' => $actorUserId === null ? min($check->next_run_at, now()) : now()]);
        $site = $check->site()->getQuery()->firstOrFail();
        LiveSignal::forRun('run.queued', $site, $checkId, actor: $attempt->actor?->name,
            queuePosition: CheckRunAttempt::query()->where('queue', $attempt->queue)->where('phase', RunPhase::Queued)->where('id', '<=', $attempt->id)->getQuery()->count());

        if (! $definition instanceof CheckDefinition) {
            $at = CarbonImmutable::now('UTC');
            $this->recorder->record(new RecordableRun($checkId, $token, $at, $at, null));

            return $attempt;
        }

        // The work slot, not this callback, recovers death between commit and Redis enqueue.
        DB::afterCommit(function () use ($checkId, $token, $definition): void {
            RunCheck::dispatch($checkId, $token, $definition->timeout, $definition->queue);
        });

        return $attempt;
    }
}
