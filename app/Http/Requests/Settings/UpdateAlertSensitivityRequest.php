<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests\Settings;

use Heptapod\Concerns\AlertSensitivityValidationRules;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpdateAlertSensitivityRequest extends FormRequest
{
    use AlertSensitivityValidationRules;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']);
    }

    /** @return array<string, list<string|Enum>> */
    public function rules(): array
    {
        return $this->alertSensitivityRules(app(InstanceSettings::class)->alert_after_minutes, false);
    }
}
