import { vi } from 'vitest';

const urlOf = (input: RequestInfo | URL) =>
    new URL(
        input instanceof Request ? input.url : input.toString(),
        'http://localhost',
    );

type PickerEntities = {
    sites?: Heptapod.Data.Ui.PickerSiteData[];
    clients?: Heptapod.Data.Ui.ClientData[];
};

/**
 * Stubs `fetch` so the server-searched pickers (`/pickers/{kind}`) answer from `entities` like PickerController: `ids`
 * names those ids, `q` matches the name. Every other request goes to `appFetch`, which the test mocks as before.
 */
export function stubPickerFetch(
    entities: PickerEntities,
    appFetch: (
        input: RequestInfo | URL,
        init?: RequestInit,
    ) => Promise<Response> = vi.fn(),
) {
    const fetch = vi.fn(
        async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = urlOf(input);
            const kind = url.pathname.match(
                /^\/pickers\/(sites|clients)$/,
            )?.[1] as keyof PickerEntities | undefined;
            if (!kind) return appFetch(input, init);
            const all: { id: number; name: string }[] = entities[kind] ?? [];
            const ids = (url.searchParams.get('ids') ?? '')
                .split(',')
                .filter(Boolean)
                .map(Number);
            const query = (url.searchParams.get('q') ?? '').toLocaleLowerCase();
            const options =
                ids.length > 0
                    ? all.filter(({ id }) => ids.includes(id))
                    : all.filter(({ name }) =>
                          name.toLocaleLowerCase().includes(query),
                      );
            return {
                ok: true,
                status: 200,
                json: async () => ({ options, more: false }),
            } as Response;
        },
    );
    vi.stubGlobal('fetch', fetch);
    return fetch;
}

/** The picker requests `fetch` received, as `kind?query` strings. */
export function pickerRequests(fetch: {
    mock: { calls: unknown[][] };
}): string[] {
    return fetch.mock.calls
        .map(([input]) => urlOf(input as RequestInfo | URL))
        .filter((url) => url.pathname.startsWith('/pickers/'))
        .map(
            (url) =>
                `${url.pathname.slice('/pickers/'.length)}?${decodeURIComponent(url.search.slice(1))}`,
        );
}
