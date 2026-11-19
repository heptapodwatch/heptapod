<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\SaveInstanceSettings;
use Heptapod\Actions\UpdateProfile;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Data\Ui\InstancePageData;
use Heptapod\Data\Ui\InstanceSettingsData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\SystemHealth\ReadSystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class InstanceController extends Controller
{
    public function edit(Request $request, InstanceSettings $settings, ReadSystemHealth $health): Response
    {
        return Inertia::render('settings/instance', new InstancePageData(
            instancePage: $this->data($request, $settings, $health),
        ));
    }

    public function show(Request $request, InstanceSettings $settings, ReadSystemHealth $health): JsonResponse
    {
        return response()->json($this->data($request, $settings, $health));
    }

    private function data(Request $request, InstanceSettings $settings, ReadSystemHealth $health): InstanceSettingsData
    {
        $this->owner($request);

        return InstanceSettingsData::current($settings, $health);
    }

    public function update(Request $request, SaveInstanceSettings $save, UpdateProfile $updateProfile): JsonResponse|RedirectResponse
    {
        $owner = $this->owner($request);
        /** @var array{name: string, mail_design?: string, timezone?: string, use_for_profile?: bool} $data */
        $data = Validator::make($request->all(), ['name' => ['required', 'string', 'max:120'], 'mail_design' => ['sometimes', 'required', Rule::enum(MailDesign::class)],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'], 'use_for_profile' => ['sometimes', 'boolean']],
            attributes: ['mail_design' => __('instance.mail_design.label')])->validate();
        $save($data['name'], isset($data['mail_design']) ? MailDesign::from($data['mail_design']) : null, $data['timezone'] ?? null);
        // The browser-zone hint offers the Owner's own profile too, but only while that profile is still on UTC.
        if (isset($data['timezone']) && ($data['use_for_profile'] ?? false) && $owner->timezone === 'UTC') {
            $updateProfile($owner, ['timezone' => $data['timezone']]);
        }

        if ($request->expectsJson()) {
            return response()->json(['name' => $data['name'], 'mail_design' => app(InstanceSettings::class)->mail_design->value]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Instance settings saved.')]);

        return to_route('settings.instance.edit');
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('Owner'), 403);

        return $user;
    }
}
