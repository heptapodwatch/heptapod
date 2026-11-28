import { Fragment, type ComponentProps, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import { Input } from '@/components/ui/input';
import {
    FilterBar,
    FilterChip,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import type { SearchedSource } from '@/components/search-combobox';
import { SegmentedControl } from '@/components/segmented-control';
import { CheckboxField } from '@/components/ui/checkbox';
import type { ComboboxOption } from '@/components/ui/combobox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';

export type ListFilters = Record<string, Heptapod.Contracts.ListFilterData>;
type Filter = Heptapod.Contracts.ListFilterData;
type FilterKind = Heptapod.Contracts.FilterKind;
type FilterSlot = Heptapod.Contracts.FilterSlot;
type Placement = FilterSlot | 'search' | 'segmented';

const placeFilter = {
    bar: (kind: FilterKind) =>
        kind === 'search' || kind === 'segmented' ? kind : 'bar',
    'history-range': () => 'history-range',
    history: () => 'history',
} satisfies Record<FilterSlot, (kind: FilterKind) => Placement>;
type Change = (changes: Record<string, string | null>) => void;
type Source = ComboboxOption[] | SearchedSource;

type ControlProps = {
    filter: Filter;
    name: string;
    label: string;
    options: ComboboxOption[];
    fixedOptions: ComboboxOption[];
    search?: SearchedSource;
    disabled: boolean;
    describedBy?: string;
    notice?: string;
    set: (value: string | null) => void;
};

const emptyChoice = 'all';
const isActive = (filter: Filter) => filter.value !== filter.default;

const renderControl = {
    select: ({ filter, label, fixedOptions, disabled, describedBy, set }) => (
        <Select
            value={filter.value ?? emptyChoice}
            disabled={disabled}
            onValueChange={(value) =>
                set(
                    value === emptyChoice && filter.empty_label !== null
                        ? null
                        : value,
                )
            }
        >
            <FilterSelectTrigger
                aria-label={label}
                aria-describedby={describedBy}
                onClear={isActive(filter) ? () => set(filter.default) : null}
            >
                <SelectValue />
            </FilterSelectTrigger>
            <SelectContent>
                {fixedOptions.map(({ value, label }) => (
                    <SelectItem key={value} value={value}>
                        {label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    ),
    combobox: ({
        filter,
        label,
        options,
        fixedOptions,
        search,
        disabled,
        describedBy,
        notice,
        set,
    }) => (
        <FilterCombobox
            notice={notice}
            search={search}
            leadingOptions={fixedOptions}
            ariaLabel={label}
            disabled={disabled}
            describedBy={describedBy}
            options={options}
            value={filter.value ?? emptyChoice}
            onValueChange={(value) =>
                set(
                    value === emptyChoice && filter.empty_label !== null
                        ? null
                        : value,
                )
            }
            onClear={isActive(filter) ? () => set(filter.default) : null}
        />
    ),
    segmented: ({ filter, label, options, set }) => (
        <SegmentedControl
            label={label}
            value={filter.value ?? ''}
            options={options}
            onValueChange={set}
        />
    ),
    chip: ({ filter, label, set }) =>
        !isActive(filter) ? null : (
            <FilterChip
                label={label}
                value={filter.display ?? label}
                {...(filter.clear_url === null
                    ? { onClear: () => set(filter.default) }
                    : { clearHref: filter.clear_url })}
            />
        ),
    search: ({ filter, name, label, set }) => (
        <Input
            type="search"
            name={name}
            className="w-full"
            aria-label={label}
            placeholder={label}
            value={filter.value ?? ''}
            onChange={(event) => set(event.target.value || null)}
        />
    ),
    date: ({ filter, label, set }) =>
        !isActive(filter) || filter.display === null ? null : (
            <FilterChip
                label={label}
                value={
                    <span>
                        {label}:{' '}
                        <AbsoluteTime
                            value={filter.display}
                            mode={
                                /^\d{4}-\d{2}-\d{2}$/.test(filter.value ?? '')
                                    ? 'calendarDate'
                                    : 'timestamp'
                            }
                        />
                    </span>
                }
                {...(filter.clear_url === null
                    ? { onClear: () => set(filter.default) }
                    : { clearHref: filter.clear_url })}
            />
        ),
    boolean: ({ filter, label, set }) => (
        <CheckboxField
            checked={filter.value === '1'}
            onCheckedChange={(checked) => set(checked === true ? '1' : null)}
        >
            {label}
        </CheckboxField>
    ),
} satisfies Record<FilterKind, (props: ControlProps) => ReactNode>;

export function filterValues(filters: ListFilters) {
    return Object.fromEntries(
        Object.entries(filters).map(([key, filter]) => [key, filter.value]),
    );
}

export function withFilterValues(
    filters: ListFilters,
    values: Record<string, string | null>,
): ListFilters {
    return Object.fromEntries(
        Object.entries(filters).map(([key, filter]) => [
            key,
            Object.hasOwn(values, key)
                ? { ...filter, value: values[key] ?? null }
                : filter,
        ]),
    );
}

export function filtersActive(filters: ListFilters) {
    return Object.values(filters).some(
        (filter) => !filter.widens && isActive(filter),
    );
}

export function filterDefaults(filters: ListFilters) {
    return Object.fromEntries(
        Object.entries(filters)
            .filter(([, filter]) => !filter.widens)
            .map(([key, filter]) => [key, filter.default]),
    );
}

type Controls = {
    filters: ListFilters;
    onChange: Change;
    sources?: Record<string, Source>;
    disabledReason?: { id: string; label: string };
    count?: number;
    optionNotices?: Record<string, string>;
};

function FilterControls({
    filters,
    onChange,
    sources,
    disabledReason,
    count,
    optionNotices,
    slot,
}: Controls & { slot: Placement }) {
    const { t } = useTranslation();
    const entries = Object.entries(filters);
    return entries.map(([key, definition]) => {
        const group = definition.group;
        const members =
            group === null
                ? [[key, definition] as const]
                : entries.filter(([, filter]) => filter.group === group);
        if (members[0]?.[0] !== key) return null;
        const selected =
            members.find(([, member]) => isActive(member))?.[1] ?? definition;
        const encode = (value: string | null) =>
            value === null ? null : `${selected.prefix ?? ''}${value}`;
        const filter = { ...definition, value: encode(selected.value) };
        if (placeFilter[filter.slot](filter.kind) !== slot) return null;
        const dependency =
            filter.disabled_by === null
                ? undefined
                : filters[filter.disabled_by];
        const disabled = dependency !== undefined && isActive(dependency);
        if (disabled && !isActive(filter)) return null;
        const source =
            filter.option_source === null
                ? []
                : sources?.[filter.option_source];
        if (!source) throw new Error(`Missing options for filter ${key}`);
        const fixedOptions = [
            ...(filter.empty_label === null
                ? []
                : [{ value: emptyChoice, label: t(filter.empty_label) }]),
            ...filter.options.map(({ value, label }) => ({
                value,
                label:
                    count !== undefined &&
                    filter.counted_option === value &&
                    filter.counted_label !== null
                        ? t(filter.counted_label, { count })
                        : t(label),
            })),
        ];
        const search = Array.isArray(source) ? undefined : source;
        const listed = Array.isArray(source) ? source : [];
        const options = [
            ...fixedOptions,
            ...listed,
            ...(selected.prefix !== null &&
            selected.value !== null &&
            filter.value !== null &&
            !listed.some(({ value }) => value === filter.value)
                ? [{ value: filter.value, label: selected.value }]
                : []),
        ];
        return (
            <Fragment key={key}>
                {renderControl[filter.kind]({
                    filter,
                    name: key,
                    label: t(filter.label),
                    options,
                    fixedOptions,
                    search,
                    disabled,
                    describedBy: disabled ? disabledReason?.id : undefined,
                    notice:
                        filter.option_source === null
                            ? undefined
                            : optionNotices?.[filter.option_source],
                    set: (value) =>
                        onChange(
                            Object.fromEntries(
                                members.map(([memberKey, member]) => {
                                    const prefix = member.prefix ?? '';
                                    return [
                                        memberKey,
                                        value !== null &&
                                        value.startsWith(prefix)
                                            ? value.slice(prefix.length)
                                            : member.default,
                                    ];
                                }),
                            ),
                        ),
                })}
            </Fragment>
        );
    });
}

export function ListFilterBar({
    filters,
    onChange,
    sources,
    disabledReason,
    onClearFilters,
    status,
    onSubmit,
    count,
    optionNotices,
}: Controls &
    Pick<
        ComponentProps<typeof FilterBar>,
        'onClearFilters' | 'status' | 'onSubmit'
    >) {
    const props = {
        filters,
        onChange,
        sources,
        disabledReason,
        count,
        optionNotices,
    };
    const kinds = Object.values(filters);
    return (
        <FilterBar
            activeCount={
                new Set(
                    Object.entries(filters)
                        .filter(
                            ([, filter]) =>
                                filter.slot === 'bar' &&
                                filter.kind !== 'search' &&
                                !filter.widens &&
                                isActive(filter),
                        )
                        .map(([key, filter]) => filter.group ?? key),
                ).size
            }
            filtersActive={filtersActive(filters)}
            onClearFilters={onClearFilters}
            status={status}
            onSubmit={onSubmit}
            search={
                kinds.some(({ kind }) => kind === 'search') && (
                    <FilterControls {...props} slot="search" />
                )
            }
            segmented={
                kinds.some(
                    ({ kind, slot }) => kind === 'segmented' && slot === 'bar',
                ) && <FilterControls {...props} slot="segmented" />
            }
        >
            <FilterControls {...props} slot="bar" />
            {disabledReason && (
                <p
                    id={disabledReason.id}
                    className="text-xs text-muted-foreground"
                >
                    {disabledReason.label}
                </p>
            )}
        </FilterBar>
    );
}

export function HistoryFilters(props: Controls) {
    return (
        <>
            <FilterControls {...props} slot="history-range" />
            <FilterControls {...props} slot="history" />
        </>
    );
}
