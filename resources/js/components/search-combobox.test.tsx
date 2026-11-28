import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { clients } from '@/fixtures/design';
import {
    SearchCombobox,
    SearchMultiCombobox,
} from '@/components/search-combobox';
import { pickerRequests, stubPickerFetch } from '@/test/picker-fetch';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));
beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

function Field({ initial }: { initial: string }) {
    const [value, setValue] = useState(initial);
    return (
        <SearchCombobox
            kind="clients"
            scope={{ archived: true }}
            ariaLabel="Client"
            leadingOptions={[{ value: 'all', label: 'All clients' }]}
            value={value}
            onValueChange={setValue}
        />
    );
}

it('names the chosen client by id and searches the server only while open', async () => {
    const [first, second] = clients;
    const fetch = stubPickerFetch({ clients });
    render(<Field initial={String(first!.id)} />);
    const trigger = screen.getByRole('combobox', { name: 'Client' });

    // The page ships no client list: the chosen id is read once, in the picker's scope.
    await waitFor(() => expect(trigger.textContent).toContain(first!.name));
    expect(pickerRequests(fetch)).toEqual([
        `clients?archived=1&ids=${first!.id}`,
    ]);

    fireEvent.click(trigger);
    expect(
        await screen.findByRole('option', { name: second!.name }),
    ).toBeTruthy();
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        {
            target: { value: second!.name },
        },
    );
    await waitFor(() =>
        expect(pickerRequests(fetch).at(-1)).toBe(
            `clients?archived=1&q=${second!.name}`,
        ),
    );
    // The fixed choice stays offered while the server supplies the matches.
    expect(screen.getByRole('option', { name: 'All clients' })).toBeTruthy();
    fireEvent.click(await screen.findByRole('option', { name: second!.name }));
    expect(trigger.textContent).toContain(second!.name);
    expect(pickerRequests(fetch)).toHaveLength(3);
});

function Multi({ initial }: { initial: string[] }) {
    const [values, setValues] = useState(initial);
    return (
        <SearchMultiCombobox
            kind="clients"
            ariaLabel="Clients"
            values={values}
            onValuesChange={setValues}
        />
    );
}

it('searches when the multi picker opens by keyboard, as by click', async () => {
    const fetch = stubPickerFetch({ clients });
    render(<Multi initial={[]} />);
    const trigger = screen.getByRole('combobox', { name: 'Clients' });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });
    expect(
        await screen.findByRole('option', { name: clients[0]!.name }),
    ).toBeTruthy();
    expect(pickerRequests(fetch)).toEqual(['clients?']);
});

it('names a large selection in batches of 500 ids', async () => {
    const many = Array.from({ length: 600 }, (_, index) => ({
        ...clients[0]!,
        id: index + 1,
        name: `Client ${index + 1}`,
    }));
    const fetch = stubPickerFetch({ clients: many });
    render(<Multi initial={many.map(({ id }) => String(id))} />);
    await waitFor(() =>
        expect(screen.getAllByRole('listitem')).toHaveLength(600),
    );
    expect(
        pickerRequests(fetch).map((request) => request.split(',').length),
    ).toEqual([500, 100]);
});

it('offers only the matches of the current query, never a slower older answer', async () => {
    const answers = new Map<string, (names: string[]) => void>();
    vi.stubGlobal(
        'fetch',
        vi.fn(
            (input: RequestInfo | URL) =>
                new Promise((resolve) => {
                    const q =
                        new URL(
                            String(input as string),
                            'http://localhost',
                        ).searchParams.get('q') ?? '';
                    answers.set(q, (names) =>
                        resolve({
                            ok: true,
                            json: async () => ({
                                options: names.map((name, index) => ({
                                    ...clients[0]!,
                                    id: index + 1 + (q === 'old' ? 100 : 0),
                                    name,
                                })),
                                more: false,
                            }),
                        } as Response),
                    );
                }),
        ),
    );
    render(<Field initial="all" />);
    fireEvent.click(screen.getByRole('combobox', { name: 'Client' }));
    const search = screen.getByRole('combobox', { name: 'combobox.search' });
    fireEvent.change(search, { target: { value: 'old' } });
    await waitFor(() => expect(answers.has('old')).toBe(true));
    fireEvent.change(search, { target: { value: 'new' } });
    await waitFor(() => expect(answers.has('new')).toBe(true));
    answers.get('new')!(['New Studio']);
    answers.get('old')!(['Old Studio']);
    expect(
        await screen.findByRole('option', { name: 'New Studio' }),
    ).toBeTruthy();
    expect(screen.queryByRole('option', { name: 'Old Studio' })).toBeNull();
});
