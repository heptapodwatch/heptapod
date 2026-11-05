<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests;

use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\IncidentCause;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IncidentsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Owner', 'Admin', 'Member']) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:status,cause,check,client_id,started_after,started_before,period'],
            'filter.status' => ['sometimes', 'nullable', Rule::in(['open', 'resolved', 'all'])],
            'filter.period' => ['sometimes', 'nullable', Rule::in(['30d', 'all'])],
            'filter.cause' => ['sometimes', 'nullable', Rule::enum(IncidentCause::class)],
            'filter.check' => ['sometimes', 'nullable', Rule::enum(CheckKey::class)],
            'filter.client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filter.started_after' => ['sometimes', 'nullable', 'date'],
            'filter.started_before' => ['sometimes', 'nullable', 'date', ...($this->filled('filter.started_after') ? ['after_or_equal:filter.started_after'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
