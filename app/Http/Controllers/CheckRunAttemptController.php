<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunReference;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Durable state of one run attempt, for the tab that requested it. The tab reconciles here after a reconnect or
 * a fallback reload, because a run.finished signal sent while it was disconnected is never replayed.
 */
final class CheckRunAttemptController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, int $attempt, SitePolicy $policy): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        Gate::authorize('view', $target);
        $row = CheckRunAttempt::query()->where('check_id', $target->id)->findOrFail($attempt);
        $terminal = in_array($row->phase, [RunPhase::Done, RunPhase::Failed, RunPhase::Skipped, RunPhase::Cancelled], true);
        $recorded = $row->check_run_id !== null && $row->finished_at !== null
            // The attempt names its own physical run; later runs never replace it.
            ? CheckRun::forRun(new RunReference($row->check_run_id, $row->finished_at))->where('check_id', $target->id)->first(['id', 'ended_at', 'result'])
            : null;

        return response()->json([
            'attempt_id' => $row->id,
            'phase' => $row->phase->value,
            'terminal' => $terminal,
            'check_run_id' => $recorded?->id,
            'ended_at' => $recorded?->ended_at->format('Y-m-d\TH:i:s.uP'),
            'result' => $recorded?->result->value,
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
