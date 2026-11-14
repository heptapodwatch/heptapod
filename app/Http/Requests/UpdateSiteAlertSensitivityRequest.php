<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests;

use Heptapod\Concerns\AlertSensitivityValidationRules;
use Heptapod\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

final class UpdateSiteAlertSensitivityRequest extends FormRequest
{
    use AlertSensitivityValidationRules;

    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site && (Gate::allows('update', $site) || ($this->has('client_id') && Gate::allows('move', $site)));
    }

    /** @return array<string, list<string|Enum>> */
    public function rules(): array
    {
        $site = $this->route('site');
        if (! $site instanceof Site) {
            throw new \LogicException('Site timing validation requires a bound site.');
        }

        return $this->alertSensitivityRules($site->alert_after_minutes, true);
    }
}
