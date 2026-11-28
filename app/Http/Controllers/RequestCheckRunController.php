<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\RequestCheckRun;
use Heptapod\Checks\Execution\ManualRunRefused;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Http\Requests\RequestCheckRunRequest;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class RequestCheckRunController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, RequestCheckRunRequest $request, int $site, int $check, SitePolicy $policy, RequestCheckRun $run, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        try {
            $attempt = $authorize($user, 'update', static fn (): CheckRunAttempt => $run($target, RunOrigin::Manual, actor: $user), $target);
        } catch (ManualRunRefused $refused) {
            $parts = ExpectedRefusal::cooldownParts($refused->retryAfterSeconds, $user);

            throw $refused->reason === 'queue_admission_limited'
                ? new ExpectedRefusal(
                    status: 429,
                    messageKey: 'errors.check_queue_busy',
                    params: ['wait' => $parts['wait']],
                    errorCode: 'queue_admission_limited',
                    retryAfterSeconds: $refused->retryAfterSeconds,
                    recoveryRoute: 'sites.checks.show',
                    recoveryRouteParams: ['site' => $site, 'check' => $check],
                    recoveryLabelKey: 'Back to check',
                )
                : new ExpectedRefusal(
                    status: 429,
                    messageKey: 'errors.check_cooldown',
                    params: $parts,
                    errorCode: 'check_cooldown',
                    retryAfterSeconds: $refused->retryAfterSeconds,
                    recoveryRoute: 'sites.checks.show',
                    recoveryRouteParams: ['site' => $site, 'check' => $check],
                    recoveryLabelKey: 'Back to check',
                );
        }

        // The requesting tab matches the later run.finished signal against this attempt.
        Inertia::flash('requested_run_attempt_id', $attempt->id);
        if ($request->has('incident')) {
            return redirect()->route('incidents.show', ['incident' => $request->integer('incident')]);
        }

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
