<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\UpdateCheckConfig;
use Heptapod\Checks\HealthSettingsProbe;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateCheckSettingsController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SitePolicy $policy, UpdateCheckConfig $update, HealthSettingsProbe $probe): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        Gate::authorize('update', $target);
        /** @var array<string, mixed> $settings */
        $settings = $request->except('_token');
        $expectedPreviousConfig = null;
        if ($target->type === CheckKey::ApplicationHealth) {
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
        $update($target, $settings, $expectedPreviousConfig);

        return back();
    }
}
