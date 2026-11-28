<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Enums\IncidentCause;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** @phpstan-import-type Filter from ListFilters */
final class IncidentsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Owner', 'Admin', 'Member']) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ListFilters::rules($this->definition(), 'filter') + [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in(ListPageSize::Sizes)],
        ];
    }

    public function listFilters(): ListFilters
    {
        $values = $this->validated('filter', []);
        if (! is_array($values)) {
            throw new \LogicException('Expected a validated incident filter array.');
        }

        return ListFilters::fromValidated($this->definition(), $values);
    }

    /** @return array<string, Filter> */
    private function definition(): array
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);
        $clearUrl = static fn (array $remaining): string => route('incidents.index', ['filter' => $remaining], absolute: false);
        $labels = [IncidentCause::Errored->value => 'Check could not run', IncidentCause::Failed->value => 'Check failed'];
        $date = static fn (string $value, array $remaining): array => [
            'display' => CarbonImmutable::parse($value, $user->resolvedTimezone())->toIso8601String(),
            'clear_url' => $clearUrl($remaining),
        ];

        return [
            'status' => ['kind' => FilterKind::Segmented, 'label' => 'Status', 'default' => IncidentsReader::DefaultStatus, 'rules' => ['nullable'],
                'options' => ['open' => 'incidents.state.open', 'resolved' => 'Resolved', 'all' => 'All'], 'counted_option' => 'open', 'counted_label' => 'Open {{count}}'],
            'client_id' => ['kind' => FilterKind::Combobox, 'label' => 'Client', 'empty_label' => 'All clients', 'source' => 'clients', 'rules' => ['nullable', 'integer', 'min:1']],
            'check' => ['kind' => FilterKind::Select, 'label' => 'Check', 'empty_label' => 'All checks', 'rules' => ['nullable'], 'enum' => CheckKey::class, 'options' => ListFilters::checkOptions()],
            'period' => ['kind' => FilterKind::Select, 'label' => 'Time range', 'default' => '30d', 'rules' => ['nullable'], 'options' => ['30d' => 'Last 30 days', 'all' => 'All time']],
            'cause' => ['kind' => FilterKind::Chip, 'label' => 'Cause', 'rules' => ['nullable'], 'enum' => IncidentCause::class,
                'options' => $labels, 'display' => static fn (string $value, array $remaining): array => ['display' => __($labels[$value]), 'clear_url' => $clearUrl($remaining)]],
            'started_after' => ['kind' => FilterKind::Date, 'label' => 'Started on or after', 'rules' => ['nullable', 'date'], 'display' => $date],
            'started_before' => ['kind' => FilterKind::Date, 'label' => 'Started on or before', 'rules' => ['nullable', 'date', ...($this->filled('filter.started_after') ? ['after_or_equal:filter.started_after'] : [])], 'display' => $date],
        ];
    }
}
