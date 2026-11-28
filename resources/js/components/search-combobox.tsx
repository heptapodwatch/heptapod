import { useEffect, useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import pickers from '@/actions/Heptapod/Http/Controllers/PickerController';
import {
    clientComboboxOptions,
    siteComboboxOptions,
} from '@/components/entity-combobox';
import {
    Combobox,
    MultiCombobox,
    type ComboboxOption,
} from '@/components/ui/combobox';

type PickerSite = Heptapod.Data.Ui.PickerSiteData;
type PickerClient = Heptapod.Data.Ui.ClientData;
type PickerEntities = { sites: PickerSite; clients: PickerClient };
export type PickerKind = keyof PickerEntities;
/**
 * Narrows a picker's search: `client` to one client's sites, `icon` to sites with a fetched icon; `archived` adds
 * archived clients, `browse` leaves out an empty Internal client, as list filters do.
 */
export type PickerScope = {
    client?: number;
    icon?: boolean;
    archived?: boolean;
    browse?: boolean;
};
/** A list filter source the server searches, instead of options shipped with the page. */
export type SearchedSource = { kind: PickerKind; scope?: PickerScope };
type PickerRead<K extends PickerKind> = {
    options: PickerEntities[K][];
    more: boolean;
};

const pickerOptions = {
    sites: (entities: PickerSite[]) => siteComboboxOptions(entities),
    clients: (entities: PickerClient[]) => clientComboboxOptions(entities),
} satisfies {
    [K in PickerKind]: (entities: PickerEntities[K][]) => ComboboxOption[];
};

const scopeParams = (scope: PickerScope) =>
    Object.fromEntries(
        Object.entries(scope).filter(([, value]) => value !== undefined),
    ) as Record<string, number | boolean>;

async function readPicker<K extends PickerKind>(
    kind: K,
    query: Record<string, string | number | boolean | number[]>,
    signal: AbortSignal,
): Promise<PickerRead<K>> {
    const response = await fetch(pickers.url(kind, { query }), {
        signal,
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
        throw new Error(`Picker ${kind} request failed: ${response.status}`);
    }
    return (await response.json()) as PickerRead<K>;
}

/**
 * The chosen sites or clients by id, read from the server when first chosen and then kept, so a choice keeps its name
 * and mark across searches; an id the user cannot see stays unnamed. `remember` keeps entities read by a search.
 */
export function usePickerEntities<K extends PickerKind>(
    kind: K,
    ids: number[],
    scope: PickerScope = {},
    onFailure: (failure: unknown) => void = console.error,
) {
    const [known, setKnown] = useState<ReadonlyMap<number, PickerEntities[K]>>(
        () => new Map(),
    );
    const remember = (entities: PickerEntities[K][]) =>
        setKnown((current) =>
            entities.every((entity) => current.get(entity.id) === entity)
                ? current
                : new Map([
                      ...current,
                      ...entities.map((entity) => [entity.id, entity] as const),
                  ]),
        );
    const missing = ids.filter((id) => !known.has(id));
    const missingKey = missing.join(',');
    useEffect(() => {
        if (missing.length === 0) return;
        const controller = new AbortController();
        readPicker(
            kind,
            { ...scopeParams(scope), ids: missing },
            controller.signal,
        ).then(
            (read) => remember(read.options),
            (failure: unknown) => {
                if (!controller.signal.aborted) onFailure(failure);
            },
        );
        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [kind, missingKey]);
    return {
        entities: ids
            .map((id) => known.get(id))
            .filter((entity) => entity !== undefined),
        known,
        remember,
    };
}

/**
 * The server search behind a picker: names the chosen ids, and while open reads the first matches for the query
 * (debounced). Options list the chosen entities missing from the matches first, as the "Link site…" sheet does.
 */
function usePickerSearch<K extends PickerKind>(
    kind: K,
    scope: PickerScope,
    chosen: number[],
) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<PickerRead<K> | null>(null);
    const [failed, setFailed] = useState(false);
    const scopeKey = JSON.stringify(scope);
    const fail = (failure: unknown) => {
        console.error(failure);
        setFailed(true);
    };
    const named = usePickerEntities(kind, chosen, scope, fail);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const search = query.trim();
        const params = scopeParams(scope);
        const read = () =>
            readPicker(
                kind,
                search === '' ? params : { ...params, q: search },
                controller.signal,
            ).then(
                (found) => {
                    named.remember(found.options);
                    setFound(found);
                },
                (failure: unknown) => {
                    if (!controller.signal.aborted) fail(failure);
                },
            );
        // Opening reads at once; typing waits for a pause.
        const timer = search === '' ? void read() : setTimeout(read, 150);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [kind, open, query, scopeKey]);
    const matches = found?.options ?? [];
    const matchIds = new Set(matches.map(({ id }) => id));
    return {
        query,
        known: named.known,
        entities: [
            ...named.entities.filter((entity) => !matchIds.has(entity.id)),
            ...matches,
        ],
        notice: failed
            ? t('combobox.failed')
            : found?.more
              ? t('combobox.more_matches')
              : undefined,
        onQueryChange: setQuery,
        onOpenChange: (next: boolean) => {
            setOpen(next);
            setFailed(false);
            if (!next) setFound(null);
        },
    };
}

type PickerShared<K extends PickerKind> = {
    kind: K;
    scope?: PickerScope;
    /** Fixed choices listed before the entities ("All clients", "Automatic"), whatever the search. */
    leadingOptions?: ComboboxOption[];
    /** Entities never offered, e.g. the client being archived as its own move target. */
    omit?: number[];
    /** Adjusts an entity's option, e.g. marks an archived client. */
    decorate?: (
        option: ComboboxOption,
        entity: PickerEntities[K],
    ) => ComboboxOption;
};

function pickerChoices<K extends PickerKind>(
    { kind, leadingOptions = [], omit = [], decorate }: PickerShared<K>,
    found: PickerEntities[K][],
): ComboboxOption[] {
    const entities = found.filter(({ id }) => !omit.includes(id));
    const options = (
        pickerOptions[kind] as (
            entities: PickerEntities[K][],
        ) => ComboboxOption[]
    )(entities);
    return [
        ...leadingOptions,
        ...(decorate
            ? options.map((option, index) => decorate(option, entities[index]!))
            : options),
    ];
}

const entityId = (value: string) =>
    /^[1-9][0-9]*$/.test(value) ? Number(value) : null;

/** A site or client picker that searches on the server, so no page ships every row. */
export function SearchCombobox<K extends PickerKind>({
    kind,
    scope = {},
    leadingOptions,
    omit,
    decorate,
    onValueChange,
    onOpenChange,
    ...props
}: PickerShared<K> &
    Omit<
        ComponentProps<typeof Combobox>,
        'options' | 'onValueChange' | 'onQueryChange'
    > & {
        /** `entity` is the chosen site or client; undefined for a leading option. */
        onValueChange: (value: string, entity?: PickerEntities[K]) => void;
    }) {
    const chosen = entityId(props.value);
    const search = usePickerSearch(
        kind,
        scope,
        chosen === null ? [] : [chosen],
    );
    return (
        <Combobox
            {...props}
            options={pickerChoices(
                { kind, leadingOptions, omit, decorate },
                search.entities,
            )}
            // A chosen value is blank, not "Choose an option", until the server names it.
            placeholder={
                chosen !== null && !search.known.has(chosen)
                    ? (props.placeholder ?? '')
                    : props.placeholder
            }
            notice={search.notice ?? props.notice}
            onQueryChange={search.onQueryChange}
            onOpenChange={(open) => {
                search.onOpenChange(open);
                onOpenChange?.(open);
            }}
            onValueChange={(value) => {
                const id = entityId(value);
                onValueChange(
                    value,
                    id === null ? undefined : search.known.get(id),
                );
            }}
        />
    );
}

/** The multi-select SearchCombobox: chips name the chosen entities without the full list. */
export function SearchMultiCombobox<K extends PickerKind>({
    kind,
    scope = {},
    leadingOptions,
    omit,
    decorate,
    onValuesChange,
    ...props
}: PickerShared<K> &
    Omit<
        ComponentProps<typeof MultiCombobox>,
        | 'options'
        | 'onValuesChange'
        | 'onQueryChange'
        | 'notice'
        | 'onOpenChange'
    > & {
        /** `entities` are the chosen sites or clients this picker has read. */
        onValuesChange: (
            values: string[],
            entities: PickerEntities[K][],
        ) => void;
    }) {
    const chosen = props.values.map(entityId).filter((id) => id !== null);
    const search = usePickerSearch(kind, scope, chosen);
    return (
        <MultiCombobox
            {...props}
            options={pickerChoices(
                { kind, leadingOptions, omit, decorate },
                search.entities,
            )}
            notice={search.notice}
            onQueryChange={search.onQueryChange}
            onOpenChange={search.onOpenChange}
            onValuesChange={(values) =>
                onValuesChange(
                    values,
                    values
                        .map(entityId)
                        .map((id) =>
                            id === null ? undefined : search.known.get(id),
                        )
                        .filter((entity) => entity !== undefined),
                )
            }
        />
    );
}
