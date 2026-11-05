<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Actions\RequestCheckRun;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Checks\Execution\ManualRunRefused;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\User;
use Heptapod\Support\Api\ApiHeadline;
use Heptapod\Support\Api\ApiProblem;
use Heptapod\Support\Api\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

final class RunController extends Controller
{
    use ResolvesApiPrincipal;

    private const int UserRunsPerMinute = 10;

    private const int TokenRunsPerMinute = 5;

    public function store(Request $request, int $check, RequestCheckRun $run, Idempotency $idempotency): JsonResponse
    {
        $user = $this->principal($request);
        $token = $this->token($user);
        $target = $this->visibleCheck($user, $check);
        Gate::authorize('update', $target);

        return $idempotency->run($request, 'check_run.create', 'check:'.$target->id, [], function () use ($target, $run, $user, $token): JsonResponse {
            // Budgets are per user and per token. One user lock serializes check through consumption, so parallel
            // distinct-key requests and token rotation cannot overshoot. Released at commit.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['manual-runs:user:'.$user->id]);
            $budgets = ['manual-runs:user:'.$user->id => self::UserRunsPerMinute, 'manual-runs:token:'.$token->id => self::TokenRunsPerMinute];
            foreach ($budgets as $key => $limit) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    throw new ManualRunRefused('manual_run_budget', max(1, RateLimiter::availableIn($key)));
                }
            }
            $attempt = $run($target, RunOrigin::Manual, actor: $user, actorTokenId: $token->id);
            foreach (array_keys($budgets) as $key) {
                RateLimiter::hit($key, 60);
                // The counter lives outside the database: an attempt that rolls back (a deadlock retry, a failed response
                // record) takes its hit back, so a request counts once, and only when it commits.
                DB::afterRollBack(static fn (): int => RateLimiter::decrement($key, 60));
            }
            $location = route('api.getRun', ['attemptRef' => 'attempt:'.$attempt->id], false);

            return response()->json(['attempt_ref' => 'attempt:'.$attempt->id, 'check_ref' => 'check:'.$target->id, 'phase' => $attempt->phase->value,
                'requested_at' => $attempt->requested_at->toIso8601ZuluString('microsecond'), 'poll_url' => $location], 202,
                ['Location' => $location, 'Retry-After' => '5', 'Cache-Control' => 'private, no-store']);
        });
    }

    public function show(Request $request, string $attemptRef, CheckRunPresentations $presentations): JsonResponse
    {
        $user = $this->principal($request);
        $this->token($user);
        /** @var array{locale?: string} $input */
        $input = Validator::make($request->query(), ['locale' => ApiHeadline::LocaleRule])->validate();
        ApiHeadline::applyLocale($input['locale'] ?? null);
        $attempt = CheckRunAttempt::query()->find((int) substr($attemptRef, strlen('attempt:')));
        if (! $attempt instanceof CheckRunAttempt) {
            throw $this->notFound();
        }
        $check = $this->visibleCheck($user, $attempt->check_id);
        $terminal = in_array($attempt->phase, [RunPhase::Done, RunPhase::Failed, RunPhase::Skipped, RunPhase::Cancelled], true);
        $result = null;
        $resultStatus = $terminal ? 'no_observation' : 'pending';
        if ($attempt->check_run_id !== null && $attempt->finished_at !== null) {
            // The attempt names its own physical run; later scheduled runs never replace it.
            $recorded = CheckRun::forRun(new RunReference($attempt->check_run_id, $attempt->finished_at))
                ->where('check_id', $attempt->check_id)->first();
            $resultStatus = $recorded instanceof CheckRun ? 'recorded' : 'evidence_expired';
            $result = $recorded instanceof CheckRun ? ['run_ref' => $recorded->reference()->toArray(), 'result' => $recorded->result->value,
                ...ApiHeadline::project($presentations->forRun($check, $recorded), $this->headline($recorded->result),
                    $check->type, $recorded->result === RunResult::Errored)] : null;
        }

        return response()->json(['attempt_ref' => 'attempt:'.$attempt->id, 'check_ref' => 'check:'.$attempt->check_id,
            'phase' => $attempt->phase->value, 'terminal' => $terminal, 'requested_at' => $attempt->requested_at->toIso8601ZuluString('microsecond'),
            'started_at' => $attempt->started_at?->toIso8601ZuluString('microsecond'), 'finished_at' => $attempt->finished_at?->toIso8601ZuluString('microsecond'),
            'progress' => ['done' => $attempt->progress_done, 'total' => $attempt->progress_total],
            'result_status' => $resultStatus, 'result' => $result], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** Generic label for retained runs whose check type has no presenter. */
    private function headline(RunResult $result): string
    {
        return match ($result) {
            RunResult::Succeeded => 'Check passed',
            RunResult::Warning => 'Check passed with warnings',
            RunResult::Failed => 'Check failed',
            RunResult::Errored => 'Check error: the check could not complete',
        };
    }

    /** Hidden and missing checks share one 404 so refs do not disclose other clients. */
    private function visibleCheck(User $user, int $checkId): Check
    {
        $check = Check::query()->find($checkId);
        if (! $check instanceof Check || Gate::forUser($user)->denies('view', $check)) {
            throw $this->notFound();
        }

        return $check;
    }

    /** One body for missing and hidden refs, independent of debug mode. */
    private function notFound(): ApiProblem
    {
        return new ApiProblem('not_found', 404);
    }
}
