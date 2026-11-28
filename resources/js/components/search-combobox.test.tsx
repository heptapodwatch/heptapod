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
import { SearchCombobox } from '@/components/search-combobox';
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
        `clients?archived=1&ids[]=${first!.id}`,
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
