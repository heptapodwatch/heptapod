<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\RequestSiteDeletion;
use Heptapod\Actions\SaveSiteSettingsForm;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Http\Requests\UpdateSiteAlertSensitivityRequest;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Container\Attributes\CurrentUser;
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
    public function edit(#[CurrentUser] User $user, int $site, SiteDetailReader $reader): Response
    {
        return Inertia::render('sites/settings', $reader->settings($user, $site))->with('instanceTimezone', app(InstanceSettings::class)->timezone);
    }

    public function update(#[CurrentUser] User $user, UpdateSiteAlertSensitivityRequest $request, Site $site, SaveSiteSettingsForm $save, SiteDetailReader $reader): RedirectResponse
    {
        Validator::make($request->all(), ['client_id' => ['sometimes', 'required', 'integer', 'min:1'], 'server_id' => ['sometimes', 'nullable', 'integer', 'min:1']])->validate();
        // The "Server" field exists only for those who may see servers, and the form sends it only when it changed; the
        // link belongs to the servers addon.
        $servers = $request->exists('server_id') ? $reader->siteServers($user) ?? abort(403) : null;
        $serverId = $request->input('server_id') === null ? null : $request->integer('server_id');
        /** @var array<string, mixed> $attributes */
        $attributes = $request->except(['_token', '_method', 'server_id']);
        /** @var array<string, mixed> $timing */
        $timing = $request->safe()->only(['alert_after_minutes', 'alert_recovery_checks', 'alert_confirms_other_checks', 'alert_reminders']);
        try {
            $save($user, $site, $attributes, $timing, $request->exists('client_id') ? $request->integer('client_id') : null, $servers, $serverId);
        } catch (CheckConfigurationValidationException $exception) {
            throw ValidationException::withMessages($exception->formErrors());
        }

        return back();
    }

    public function destroy(#[CurrentUser] User $user, Request $request, Site $site, RequestSiteDeletion $delete, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        Gate::authorize('delete', $site);
        Validator::make($request->all(), ['name' => ['required', 'string', Rule::in([$site->displayName()])]])->validate();
        // Decided again under the shared access lock, with the actor as the account is now.
        $authorize($user, 'delete', static fn () => $delete($site), $site);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Site deleted')]);

        return redirect()->route('sites.index');
    }
}
