<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\UpdateAlertSensitivity;
use Heptapod\Data\Ui\AlertSensitivityData;
use Heptapod\Data\Ui\AlertSensitivitySettingsPageData;
use Heptapod\Enums\AlertReminders;
use Heptapod\Enums\AlertSensitivity;
use Heptapod\Events\Notifications\EffectiveAlertSensitivity;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Settings\UpdateAlertSensitivityRequest;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class AlertSensitivityController extends Controller
{
    public function edit(InstanceSettings $settings): Response
    {
        return Inertia::render('settings/alerts', new AlertSensitivitySettingsPageData(
            AlertSensitivityData::fromEffective(EffectiveAlertSensitivity::team($settings)),
            AlertSensitivityData::presets(),
            AlertSensitivity::bounds(),
        )->toArray());
    }

    public function update(UpdateAlertSensitivityRequest $request, UpdateAlertSensitivity $update): RedirectResponse
    {
        $update(new EffectiveAlertSensitivity($request->integer('alert_after_minutes'), $request->integer('alert_recovery_checks'),
            $request->boolean('alert_confirms_other_checks'), $request->enum('alert_reminders', AlertReminders::class) ?? throw new \LogicException('Validated reminders are missing.'), false));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('alert_sensitivity.saved')]);

        return back();
    }
}
