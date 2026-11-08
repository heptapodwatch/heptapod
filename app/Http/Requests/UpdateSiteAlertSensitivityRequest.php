<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests;

use Heptapod\Concerns\AlertSensitivityValidationRules;
use Heptapod\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class UpdateSiteAlertSensitivityRequest extends FormRequest
{
    use AlertSensitivityValidationRules;

    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site && (Gate::allows('update', $site) || ($this->has('client_id') && Gate::allows('move', $site)));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $site = $this->route('site');
        if (! $site instanceof Site) {
            throw new \LogicException('Site timing validation requires a bound site.');
        }

        return [
            'alert_after_minutes' => ['present_with:alert_recovery_checks', 'required_with:alert_recovery_checks', 'nullable', 'integer', $this->alertAfterMinutesRange($site->alert_after_minutes, $this->input('alert_after_minutes'))],
            'alert_recovery_checks' => ['present_with:alert_after_minutes', 'required_with:alert_after_minutes', 'nullable', 'integer', $this->alertRecoveryChecksRange()],
        ];
    }
}
