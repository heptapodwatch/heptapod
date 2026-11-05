<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests\Settings;

use Heptapod\Concerns\AlertSensitivityValidationRules;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAlertSensitivityRequest extends FormRequest
{
    use AlertSensitivityValidationRules;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'alert_after_minutes' => ['required', 'integer', $this->alertAfterMinutesRange(app(InstanceSettings::class)->alert_after_minutes, $this->input('alert_after_minutes'))],
            'alert_recovery_checks' => ['required', 'integer', $this->alertRecoveryChecksRange()],
        ];
    }
}
