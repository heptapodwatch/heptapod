export function listFilter(
    kind: Heptapod.Contracts.FilterKind,
    label: string,
    data: Partial<Heptapod.Contracts.ListFilterData> = {},
): Heptapod.Contracts.ListFilterData {
    return {
        kind,
        label,
        value: data.default ?? null,
        default: null,
        options: [],
        empty_label: null,
        option_source: null,
        slot: 'bar',
        widens: false,
        disabled_by: null,
        display: null,
        clear_url: null,
        detail_url: null,
        group: null,
        prefix: null,
        counted_option: null,
        counted_label: null,
        ...data,
    };
}
