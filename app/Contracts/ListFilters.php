<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use LogicException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @phpstan-type Filter array{kind: FilterKind, label: string, rules?: list<string>, enum?: class-string<BackedEnum>, options?: array<array-key, string|null>, default?: string, empty_label?: string, source?: string, slot?: FilterSlot, predicate?: Closure(string): AllowedFilter, widens?: bool, disabled_by?: string, display?: Closure(string, array<array-key, mixed>): array{display: string, clear_url?: string, detail_url?: string}, group?: string, prefix?: string, counted_option?: string, counted_label?: string}
 */
final readonly class ListFilters
{
    /**
     * @param  array<string, Filter>  $definition
     * @param  array<array-key, mixed>  $validated
     */
    private function __construct(private array $definition, private array $validated) {}

    /**
     * @param  array<string, Filter>  $definition
     * @param  array{page?: list<string|In>, per_page?: list<string|In>, sort?: list<string|In>, before?: list<string|In>, group?: list<string|In>}  $pagingAndSortRules
     */
    public static function read(Request $request, array $definition, array $pagingAndSortRules = [], ?string $nested = null, bool $queryOnly = false): self
    {
        $validated = Validator::make($queryOnly ? $request->query() : $request->all(), self::rules($definition, $nested) + $pagingAndSortRules)->validate();
        $filters = $nested === null ? $validated : ($validated[$nested] ?? []);
        if (! is_array($filters)) {
            throw new LogicException('Expected a validated filter array.');
        }

        return self::fromValidated($definition, $filters + Arr::only($validated, array_keys($pagingAndSortRules)));
    }

    /**
     * @param  array<string, Filter>  $definition
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromValidated(array $definition, array $validated): self
    {
        return new self($definition, $validated);
    }

    /**
     * @param  array<string, Filter>  $definition
     * @return array<string, list<string|In|Enum>>
     */
    public static function rules(array $definition, ?string $nested = null): array
    {
        $rules = [];
        if ($nested !== null) {
            $rules[$nested] = ['sometimes', 'array:'.implode(',', array_keys($definition))];
        }
        foreach ($definition as $key => $filter) {
            $name = $nested === null ? $key : "$nested.$key";
            $rules[$name] = ['sometimes', ...($filter['rules'] ?? [])];
            if (isset($filter['enum'])) {
                $cases = $filter['enum']::cases();
                $values = array_column($cases, 'value');
                if (array_diff($values, array_keys($filter['options'] ?? [])) !== [] || array_diff(array_keys($filter['options'] ?? []), $values) !== []) {
                    throw new LogicException("Enum filter $name must declare every option and no extras.");
                }
                $rules[$name][] = Rule::enum($filter['enum']);
            } elseif (isset($filter['options'])) {
                $rules[$name][] = Rule::in(array_keys($filter['options']));
            }
        }

        return $rules;
    }

    public function value(string $key): ?string
    {
        $filter = $this->definition[$key] ?? throw new LogicException("Unknown list filter: $key.");
        $value = $this->validated[$key] ?? null;
        if (in_array('boolean', $filter['rules'] ?? [], true)) {
            return in_array($value, [true, 1, '1'], true) ? '1' : ($filter['default'] ?? null);
        }
        if (in_array('integer', $filter['rules'] ?? [], true) && (is_int($value) || is_string($value))) {
            return (string) (int) $value;
        }

        return is_scalar($value) ? (string) $value : ($filter['default'] ?? null);
    }

    public function hasValue(string $key): bool
    {
        return isset($this->validated[$key]);
    }

    public function integer(string $key): ?int
    {
        $value = $this->value($key);

        return $value === null ? null : (int) $value;
    }

    /** @return array<string, ListFilterData> */
    public function data(): array
    {
        $result = [];
        foreach ($this->definition as $key => $filter) {
            $value = $this->value($key);
            $options = [];
            foreach ($filter['options'] ?? [] as $option => $label) {
                if ($label !== null) {
                    $options[] = ['value' => (string) $option, 'label' => $label];
                }
            }
            $display = isset($filter['display']) && $value !== null
                ? $filter['display']($value, Arr::except($this->validated, [$key]))
                : [];
            $result[$key] = new ListFilterData(
                kind: $filter['kind'], label: $filter['label'], value: $value, default: $filter['default'] ?? null,
                options: $options, empty_label: $filter['empty_label'] ?? null, option_source: $filter['source'] ?? null,
                slot: $filter['slot'] ?? FilterSlot::Bar, widens: $filter['widens'] ?? false, disabled_by: $filter['disabled_by'] ?? null,
                display: $display['display'] ?? null, clear_url: $display['clear_url'] ?? null, detail_url: $display['detail_url'] ?? null,
                group: $filter['group'] ?? null, prefix: $filter['prefix'] ?? null,
                counted_option: $filter['counted_option'] ?? null, counted_label: $filter['counted_label'] ?? null,
            );
        }

        return $result;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @return QueryBuilder<TModel>
     */
    public function query(Builder $builder): QueryBuilder
    {
        $allowed = [];
        foreach ($this->definition as $key => $filter) {
            $predicate = $filter['predicate'] ?? throw new LogicException("List filter $key has no predicate.");
            $binding = $predicate($key);
            if ($binding->getName() !== $key) {
                throw new LogicException("List filter $key has a predicate for {$binding->getName()}.");
            }
            $allowed[] = $binding;
        }

        // Spatie gets only validated values, retaining its existing delimiter, null and default handling.
        return QueryBuilder::for($builder, new Request([
            'filter' => Arr::only($this->validated, array_keys($this->definition)),
            'sort' => $this->parameter('sort'),
        ]))->allowedFilters(...$allowed);
    }

    /**
     * @param  Closure(BaseBuilder, string): void  $predicate
     * @return Closure(string): AllowedFilter
     */
    public static function literalPredicate(Closure $predicate): Closure
    {
        return static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($predicate): void {
            // QueryBuilderRequest maps exactly these two strings to booleans.
            $value = match ($value) {
                true => 'true', false => 'false', default => $value,
            };
            if (is_string($value) && $value !== '') {
                $predicate($query->getQuery(), $value);
            }
        })->delimiter('');
    }

    /** @return array<string, string> Labels in the shared check picker order. */
    public static function checkOptions(): array
    {
        $ordered = [CheckKey::Uptime, CheckKey::Performance, CheckKey::CertificateHealth, CheckKey::Domain, CheckKey::Dns, CheckKey::Sitemap, CheckKey::BrokenLinks, CheckKey::MixedContent, CheckKey::Lighthouse, CheckKey::Cron, CheckKey::ApplicationHealth];
        $options = [];
        foreach ([...$ordered, ...CheckKey::cases()] as $key) {
            $options[$key->value] = "checks.{$key->value}.label";
        }

        return $options;
    }

    /** Paging and sorting are not rendered by the filter bar. */
    public function parameter(string $key): mixed
    {
        return $this->validated[$key] ?? null;
    }
}
