<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

use Heptapod\Contracts\RunCancelled;
use Heptapod\Contracts\RunControl;
use Heptapod\Contracts\RunProgress;
use Heptapod\Events\LiveSignal;
use Heptapod\Models\CheckRunAttempt;
use Illuminate\Support\Facades\DB;

final class AttemptControl implements RunControl, RunProgress
{
    private int $lastReport = 0;

    private int $lastActiveCheck = 0;

    public function __construct(private readonly int $checkId, private readonly string $claimToken) {}

    public function assertActive(): void
    {
        $this->checkActive(false);
    }

    private function checkActive(bool $force): void
    {
        $now = hrtime(true);
        if (! $force && $now - $this->lastActiveCheck < 1_000_000_000) {
            return;
        }
        $attempt = CheckRunAttempt::query()->where('claim_token', $this->claimToken)->firstOrFail();
        $this->lastActiveCheck = $now;
        if ($attempt->cancel_requested_at !== null) {
            throw new RunCancelled('Check run cancelled.');
        }
    }

    public function report(int $done, int $total, string $phase): void
    {
        if ($done < 0 || $total < 1 || $done > $total || strlen($phase) > 64) {
            throw new \InvalidArgumentException('Invalid run progress.');
        }
        $this->assertActive();
        $now = hrtime(true);
        if ($now - $this->lastReport < 1_000_000_000) {
            return;
        }
        $updated = DB::table('check_run_attempts')->where('claim_token', $this->claimToken)->where('phase', 'running')
            ->whereNull('cancel_requested_at')->update(['progress_done' => $done, 'progress_total' => $total, 'phase_label' => $phase]);
        if ($updated === 0) {
            $this->checkActive(true);

            return;
        }
        $this->lastReport = $now;
        $attempt = CheckRunAttempt::query()->with('check.site', 'actor')->where('claim_token', $this->claimToken)->firstOrFail();
        LiveSignal::forRun('run.progress', $attempt->check()->getQuery()->firstOrFail()->site()->getQuery()->firstOrFail(), $this->checkId, $attempt->started_at, $attempt->actor?->name, progressN: $done, progressTotal: $total);
    }
}
