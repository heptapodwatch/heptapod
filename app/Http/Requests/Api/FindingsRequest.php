<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests\Api;

use Heptapod\Contracts\Findings\FindingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class FindingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $reference = ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}(?:Z|[+-]\d{2}:\d{2})$/', 'date'];

        return [
            'incident_id' => ['required_without:check_id', 'prohibits:check_id,run_ref,baseline_run_ref', 'integer', 'min:1'],
            'snapshot' => [Rule::prohibitedIf(! $this->has('incident_id')), Rule::in(['opening', 'current'])],
            'check_id' => ['required_without:incident_id', 'prohibits:incident_id,snapshot', 'integer', 'min:1'],
            'run_ref' => ['required_with:check_id', 'array:id,endedAt', 'required_array_keys:id,endedAt'],
            'run_ref.id' => ['required_with:run_ref', 'integer', 'min:1'],
            'run_ref.endedAt' => ['required_with:run_ref', ...array_slice($reference, 1)],
            'baseline_run_ref' => ['sometimes', 'array:id,endedAt', 'required_array_keys:id,endedAt'],
            'baseline_run_ref.id' => ['required_with:baseline_run_ref', 'integer', 'min:1'],
            'baseline_run_ref.endedAt' => ['required_with:baseline_run_ref', ...array_slice($reference, 1)],
            'status' => ['sometimes', Rule::enum(FindingStatus::class)],
            'only_new_since' => ['sometimes', Rule::in(['opening', 'baseline'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:16384'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('query', __('validation.prohibited', ['attribute' => 'query']));
            }
            $comparison = $this->input('only_new_since');
            if (($comparison === 'opening' && ! $this->has('incident_id'))
                || ($comparison === 'baseline' && (! $this->has('check_id') || ! $this->has('baseline_run_ref')))) {
                $validator->errors()->add('only_new_since', __('validation.in', ['attribute' => 'only_new_since']));
            }
        }];
    }
}
