<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AcceptDnsSnapshot;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class AcceptDnsSnapshotController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SitePolicy $sites, AcceptDnsSnapshot $accept, CheckRegistry $registry): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $visibleSite = $sites->visibleSites($actor)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        abort_unless($target->type === CheckKey::Dns, 404);
        Gate::authorize('update', $target);
        /** @var array{accepted_run_id: int} $values */
        $values = Validator::make($request->all(), ['accepted_run_id' => ['required', 'integer', 'min:1']])->validate();
        try {
            $attempt = $accept($actor, $target, $values['accepted_run_id']);
        } catch (ValidationException $error) {
            // Acceptance must roll back before translating a lease refusal. Re-read eligibility to cover a render/click race.
            if ($error->errors() === ['check' => [__('api.run_unavailable')]]) {
                $target->refresh();
                $visibleSite->refresh();
                if ($target->enabled && ! in_array($target->type, $registry->unavailableKeys(), true) && $visibleSite->paused_at === null && $visibleSite->marked_for_deletion_at === null
                    && $target->lease_expires_at?->isFuture() === true) {
                    throw ValidationException::withMessages(['check' => __('A check is running right now. Try again in a few seconds.')]);
                }
            }
            throw $error;
        }
        Inertia::flash('requested_run_attempt_id', $attempt->id);

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
