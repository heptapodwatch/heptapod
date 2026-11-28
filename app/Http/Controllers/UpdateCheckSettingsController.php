<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\UpdateCheckConfig;
use Heptapod\Checks\HealthSettingsProbe;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateCheckSettingsController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, int $site, int $check, SitePolicy $policy, UpdateCheckConfig $update, HealthSettingsProbe $probe, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        Gate::authorize('update', $target);
        /** @var array<string, mixed> $settings */
        $settings = $request->except('_token');
        $expectedPreviousConfig = null;
        $findingEdit = null;
        if ($request->has('ignore_finding')) {
            abort_unless($target->type === CheckKey::BrokenLinks, 404);
            /** @var array{ignore_finding: array{url: string, ignore: bool|int|string}} $validated */
            $validated = Validator::make($request->all(), ['ignore_finding' => ['required', 'array:url,ignore'], 'ignore_finding.url' => ['required', 'string'], 'ignore_finding.ignore' => ['required', 'boolean']])->validate();
            $findingEdit = ['url' => $validated['ignore_finding']['url'], 'ignore' => (bool) $validated['ignore_finding']['ignore']];
        } elseif ($target->type === CheckKey::ApplicationHealth) {
            $expectedPreviousConfig = $target->executionConfig();
            if (($settings['secret'] ?? null) === '') {
                $settings['secret'] = null;
            }
            /** @var array<string, mixed> $endpointSettings */
            $endpointSettings = Arr::except($settings, ['frequency_value', 'frequency_unit']);
            if ($probe($target, $user, $endpointSettings)['result'] !== 'ok') {
                throw ValidationException::withMessages(['result_url' => __('checks.health_probe_required')]);
            }
        }
        // The probe (an outbound request) stays outside the lock; the save is decided again under the shared access lock with
        // the actor as the account is now, and refuses a configuration that changed since the probe ($expectedPreviousConfig).
        $authorize($user, 'update', static fn () => $update($target, $settings, $expectedPreviousConfig, $findingEdit), $target);

        return back();
    }
}
