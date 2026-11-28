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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

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
        $timezone = $user->resolvedTimezone();
        $clearUrl = static fn (array $remaining): string => route('incidents.index', ['filter' => $remaining], absolute: false);
        $labels = [IncidentCause::Errored->value => 'Check could not run', IncidentCause::Failed->value => 'Check failed'];
        $date = static fn (string $value, array $remaining): array => [
            'display' => CarbonImmutable::parse($value, $user->resolvedTimezone())->toIso8601String(),
            'clear_url' => $clearUrl($remaining),
        ];

        return [
            'status' => ['kind' => FilterKind::Segmented, 'label' => 'Status', 'default' => IncidentsReader::DefaultStatus, 'rules' => ['nullable'],
                'options' => ['open' => 'incidents.state.open', 'resolved' => 'Resolved', 'all' => 'All'], 'counted_option' => 'open', 'counted_label' => 'Open {{count}}',
                'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value): void {
                    match ($value) {
                        'open' => $query->getQuery()->whereNull('resolved_at'),
                        'resolved' => $query->getQuery()->whereNotNull('resolved_at'),
                        'all' => null,
                        default => throw new \LogicException('Incident status filter must be validated.'),
                    };
                })->default(IncidentsReader::DefaultStatus)],
            'client_id' => ['kind' => FilterKind::Combobox, 'label' => 'Client', 'empty_label' => 'All clients', 'source' => 'clients', 'rules' => ['nullable', 'integer', 'min:1'], 'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::exact($key, 'site.client_id')],
            'check' => ['kind' => FilterKind::Select, 'label' => 'Check', 'empty_label' => 'All checks', 'rules' => ['nullable'], 'enum' => CheckKey::class, 'options' => ListFilters::checkOptions(), 'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::exact($key, 'check.type')],
            'period' => ['kind' => FilterKind::Select, 'label' => 'Time range', 'default' => '30d', 'rules' => ['nullable'], 'options' => ['30d' => 'Last 30 days', 'all' => 'All time'],
                'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value): void {
                    if ($value === '30d') {
                        $query->getQuery()->where(static fn (QueryBuilder $period) => $period->where('first_failure_at', '>=', CarbonImmutable::now('UTC')->subDays(30)->format('Y-m-d H:i:s.uP'))->orWhereNull('resolved_at'));
                    }
                })],
            'cause' => ['kind' => FilterKind::Chip, 'label' => 'Cause', 'rules' => ['nullable'], 'enum' => IncidentCause::class,
                'options' => $labels, 'predicate' => AllowedFilter::exact(...), 'display' => static fn (string $value, array $remaining): array => ['display' => __($labels[$value]), 'clear_url' => $clearUrl($remaining)]],
            'started_after' => ['kind' => FilterKind::Date, 'label' => 'Started on or after', 'rules' => ['nullable', 'date'], 'display' => $date,
                'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($timezone): void {
                    if (! is_string($value)) {
                        throw new \LogicException('Incident date filter must be validated as a string.');
                    }
                    $start = CarbonImmutable::parse($value, $timezone)->utc();
                    $query->getQuery()->where('first_failure_at', '>=', $start->format('Y-m-d H:i:s.uP'));
                })],
            'started_before' => ['kind' => FilterKind::Date, 'label' => 'Started on or before', 'rules' => ['nullable', 'date', ...($this->filled('filter.started_after') ? ['after_or_equal:filter.started_after'] : [])], 'display' => $date,
                'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($timezone): void {
                    if (! is_string($value)) {
                        throw new \LogicException('Incident date filter must be validated as a string.');
                    }
                    $end = CarbonImmutable::parse($value, $timezone);
                    $wholeDay = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1;
                    $end = $wholeDay ? $end->addDay() : $end;
                    $query->getQuery()->where('first_failure_at', $wholeDay ? '<' : '<=', $end->utc()->format('Y-m-d H:i:s.uP'));
                })],
        ];
    }
}
