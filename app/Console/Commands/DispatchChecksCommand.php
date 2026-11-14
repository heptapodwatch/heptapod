<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\Actions\RecordDispatcherHeartbeat;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Description('Claim due checks and reconcile expired execution leases')]
#[Signature('heptapod:dispatch {--once : Dispatch one bounded batch} {--batch=100 : Maximum checks per tick}')]
final class DispatchChecksCommand extends Command
{
    public function handle(ClaimService $claims, RecordDispatcherHeartbeat $heartbeat, EvaluateSystemIssues $evaluate): int
    {
        $stopping = false;
        $this->trap([SIGTERM, SIGINT], function () use (&$stopping): void {
            $stopping = true;
        });
        $id = gethostname().':'.getmypid();
        $evaluatedAt = hrtime(true);
        do {
            $tick = hrtime(true);
            $maintenance = app()->isDownForMaintenance();
            if (! $maintenance) {
                $claims->dispatchDue((int) $this->option('batch'));
            }
            $heartbeat($id);
            if (! $maintenance && hrtime(true) - $evaluatedAt >= 300_000_000_000) {
                $evaluatedAt = hrtime(true);
                $this->evaluateWhileSchedulerStale($evaluate);
            }
            if ($this->option('once') === true) {
                break;
            }
            while (! $stopping && hrtime(true) - $tick < 5_000_000_000) {
                usleep(100_000);
            }
        } while (! $stopping);

        return self::SUCCESS;
    }

    /** The scheduler normally evaluates system issues; when it is the thing that stopped, the dispatcher reports it. */
    private function evaluateWhileSchedulerStale(EvaluateSystemIssues $evaluate): void
    {
        $tick = Cache::store('redis')->get(config()->string('heptapod.health.scheduler_cache_key'));
        if (is_string($tick) && CarbonImmutable::parse($tick)->diffInSeconds(CarbonImmutable::now('UTC')) <= EvaluateSystemIssues::HeartbeatStaleAfterSeconds) {
            return;
        }
        try {
            $evaluate(CarbonImmutable::now('UTC'));
        } catch (Throwable $exception) {
            // The evaluation recorded its failure for System health; a failed evaluation must not stop check dispatch.
            report($exception);
        }
    }
}
