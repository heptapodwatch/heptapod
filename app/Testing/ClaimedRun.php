<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\UptimeDependency;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\HistoryReader;
use Heptapod\Contracts\RunPhase;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

final class ClaimedRun
{
    /**
     * Claim through the real dispatcher, then place the attempt at a controlled test clock before recording.
     *
     * @param  array<string, mixed>|null  $config
     * @param  list<array{name: string, value: string}>  $headers
     */
    public static function start(Check $check, ?CarbonImmutable $at = null, ?int $runId = null, ?array $config = null, array $headers = [], ?HistoryReader $history = null): CheckContext
    {
        Bus::fake([RunCheck::class]);
        if (! app(ClaimService::class)->runNow($check->id, RunOrigin::System) instanceof CheckRunAttempt) {
            throw new \LogicException('Test check must be eligible and unclaimed.');
        }
        $check->refresh();
        $at ??= CarbonImmutable::now('UTC');
        if ($runId === null) {
            /** @var int $reservedRunId */
            $reservedRunId = DB::scalar("SELECT nextval('check_runs_id_seq')");
            $runId = $reservedRunId;
        }
        $token = $check->claim_token ?? throw new \LogicException('Claim must have a token.');
        $check->forceFill(['run_started_at' => $at, 'active_run_id' => $runId])->save();
        CheckRunAttempt::query()->where('claim_token', $token)->update(['phase' => RunPhase::Running, 'started_at' => $at]);
        $definition = app(CheckRegistry::class)->get($check->type)->definition();
        $site = $check->site()->getQuery()->firstOrFail();

        return new CheckContext($site->id, $check->id, $runId, $token, $site->url, $at,
            $at->addSeconds($definition->timeout), $config ?? $check->executionConfig(), $headers, null, $history, uptimeIntervalSeconds: $check->type === CheckKey::Performance ? app(UptimeDependency::class)->check($site->id)?->interval_seconds : null);
    }
}
