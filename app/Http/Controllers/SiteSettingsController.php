<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\DeleteSite;
use Heptapod\Actions\UpdateSiteSettings;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Http\Requests\UpdateSiteAlertSensitivityRequest;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SiteSettingsController extends Controller
{
    public function edit(Request $request, int $site, SiteDetailReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('sites/settings', $reader->settings($user, $site));
    }

    public function update(UpdateSiteAlertSensitivityRequest $request, Site $site, UpdateSiteSettings $update): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), ['client_id' => ['sometimes', 'required', 'integer', 'min:1']])->validate();
        /** @var array<string, mixed> $attributes */
        $attributes = $request->except(['_token', '_method']);
        /** @var array<string, mixed> $timing */
        $timing = $request->safe()->only(['alert_after_minutes', 'alert_recovery_checks']);
        try {
            $update($user, $site, $attributes, $timing, $request->exists('client_id') ? $request->integer('client_id') : null);
        } catch (CheckConfigurationValidationException $exception) {
            throw ValidationException::withMessages($exception->formErrors());
        }

        return back();
    }

    public function destroy(Request $request, Site $site, DeleteSite $delete): RedirectResponse
    {
        Gate::authorize('delete', $site);
        Validator::make($request->all(), ['name' => ['required', 'string', Rule::in([$site->displayName()])]])->validate();
        $delete($site);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Site deleted')]);

        return redirect()->route('sites.index');
    }
}
