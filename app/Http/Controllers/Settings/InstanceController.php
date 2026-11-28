<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\SaveInstanceSettings;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Data\Ui\InstancePageData;
use Heptapod\Data\Ui\InstanceSettingsData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class InstanceController extends Controller
{
    public function edit(InstanceSettings $settings): Response
    {
        return Inertia::render('settings/instance', new InstancePageData(
            instancePage: InstanceSettingsData::current($settings),
        ));
    }

    public function show(InstanceSettings $settings): JsonResponse
    {
        return response()->json(InstanceSettingsData::current($settings));
    }

    public function update(Request $request, SaveInstanceSettings $save): JsonResponse|RedirectResponse
    {
        /** @var array{name: string, mail_design?: string, timezone?: string, servers_enabled?: bool} $data */
        $data = Validator::make($request->all(), ['name' => ['required', 'string', 'max:120'], 'mail_design' => ['sometimes', 'required', Rule::enum(MailDesign::class)],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'], 'servers_enabled' => ['sometimes', 'boolean:strict']],
            attributes: ['mail_design' => __('instance.mail_design.label')])->validate();
        $save($data['name'], isset($data['mail_design']) ? MailDesign::from($data['mail_design']) : null, $data['timezone'] ?? null,
            $data['servers_enabled'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['name' => $data['name'], 'mail_design' => app(InstanceSettings::class)->mail_design->value]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Instance settings saved.')]);

        return to_route('settings.instance.edit');
    }
}
