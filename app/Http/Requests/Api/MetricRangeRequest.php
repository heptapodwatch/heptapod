<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests\Api;

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\RetentionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class MetricRangeRequest extends FormRequest
{
    private ?CarbonImmutable $capturedNow = null;

    private function capturedNow(): CarbonImmutable
    {
        return $this->capturedNow ??= CarbonImmutable::now('UTC');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['filter' => ['sometimes', 'array:start,end,group_by,started_at,ended_at'], 'filter.start' => ['sometimes', 'date_format:YmdHis'],
            'filter.end' => ['sometimes', 'date_format:YmdHis', 'after:filter.start'], 'filter.started_at' => ['sometimes', 'date_format:YmdHis'],
            'filter.ended_at' => ['sometimes', 'date_format:YmdHis', 'after:filter.started_at'], 'filter.group_by' => ['sometimes', 'in:minute,hour,day,week,month'], 'split' => ['sometimes', 'in:hour,day,month']];
    }

    public function start(bool $metrics = false): CarbonImmutable
    {
        $value = $this->input($metrics ? 'filter.start' : 'filter.started_at');

        return is_string($value) ? (CarbonImmutable::createFromFormat('!YmdHis', $value, 'UTC') ?? throw new \LogicException('Validated date could not be parsed.')) : $this->capturedNow()->subDay();
    }

    public function end(bool $metrics = false): CarbonImmutable
    {
        $value = $this->input($metrics ? 'filter.end' : 'filter.ended_at');

        return is_string($value) ? (CarbonImmutable::createFromFormat('!YmdHis', $value, 'UTC') ?? throw new \LogicException('Validated date could not be parsed.')) : $this->capturedNow();
    }

    public function validateWindow(bool $metrics = false): void
    {
        $policy = RetentionPolicy::fromConfig();
        if (! $metrics && $this->start()->lessThan($this->capturedNow()->subDays($policy->historyDays))) {
            throw ValidationException::withMessages(['filter.started_at' => __('api.history_retention', ['days' => $policy->historyDays])]);
        }
        if ($this->start($metrics)->greaterThanOrEqualTo($this->end($metrics)) || $this->start($metrics)->diffInHours($this->end($metrics)) > 10000) {
            throw ValidationException::withMessages(['filter' => __('api.invalid_window')]);
        }
    }
}
