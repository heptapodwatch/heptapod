<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\CheckPending;
use Heptapod\Contracts\RunPhase;
use Heptapod\Events\LiveSignal;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CancelCheckRun
{
    public function __invoke(Check $check): void
    {
        DB::transaction(function () use ($check): void {
            $query = Check::query()->whereKey($check->id);
            $query->getQuery()->lockForUpdate();
            $current = $query->firstOrFail();
            $attempt = CheckRunAttempt::query()->with('actor')->where('claim_token', $current->claim_token)->first();
            if (! $attempt instanceof CheckRunAttempt || ! in_array($attempt->phase, [RunPhase::Queued, RunPhase::Running], true)
                || ! $current->type->canCancelRun()) {
                throw ValidationException::withMessages(['check' => __('api.run_unavailable')]);
            }
            if ($attempt->cancel_requested_at !== null) {
                return;
            }
            $attempt->update(['cancel_requested_at' => now()]);
            if ($attempt->phase === RunPhase::Queued) {
                $attempt->update(['phase' => RunPhase::Cancelled, 'finished_at' => now(), 'note' => 'cancelled']);
                $current->update(['awaiting_result_since' => null, 'lost_attempts' => 0, 'last_loss_started_at' => null, 'claim_token' => null, 'dispatched_at' => null, 'lease_expires_at' => null,
                    'run_started_at' => null, 'active_run_id' => null, 'run_headers' => null]);
                $cleared = CheckPending::clear($current);
                $site = $current->site()->getQuery()->firstOrFail();
                LiveSignal::forRun('run.finished', $site, $current->id, actor: $attempt->actor?->name, outcome: RunPhase::Cancelled, attemptId: $attempt->id);
                // A queued cancel stores no result; a cleared mark still moves the site off pending.
                if ($cleared) {
                    LiveSignal::forSite($site, ['check' => $current->id]);
                }
            }
        });
    }
}
