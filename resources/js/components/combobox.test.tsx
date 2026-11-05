import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { Combobox, MultiCombobox, type ComboboxCreate } from './ui/combobox';
import { TooltipProvider } from './ui/tooltip';

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

function Field({
    create,
    empty = false,
}: {
    create?: ComboboxCreate;
    empty?: boolean;
}) {
    const [value, setValue] = useState('1');
    return (
        <>
            <label htmlFor="client">Client</label>
            <Combobox
                id="client"
                options={
                    empty ? [] : [{ value: '1', label: 'Existing Studio' }]
                }
                value={value}
                onValueChange={setValue}
                create={create}
            />
        </>
    );
}
const createLabel = (name: string) =>
    name ? `Create client "${name}"` : 'New client';

it('always offers a terminal create option when opted in, including empty search and exact matches', () => {
    const create = { label: createLabel, onCreate: vi.fn() };
    const view = render(<Field create={create} />);
    fireEvent.click(screen.getByLabelText('Client'));
    expect(screen.getAllByRole('option').at(-1)?.textContent).toBe(
        'New client',
    );
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        {
            target: { value: 'Existing Studio' },
        },
    );
    expect(screen.getAllByRole('option').at(-1)?.textContent).toBe(
        'Create client "Existing Studio"',
    );
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        {
            target: { value: 'New Studio' },
        },
    );
    expect(screen.getAllByRole('option').at(-1)?.textContent).toBe(
        'Create client "New Studio"',
    );
    view.unmount();
    render(<Field />);
    fireEvent.click(screen.getByLabelText('Client'));
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        {
            target: { value: 'New Studio' },
        },
    );
    expect(
        screen.queryByRole('option', { name: /Create client|New client/ }),
    ).toBeNull();
});

it('opens creation without typing when there are no clients', () => {
    const onCreate = vi.fn();
    render(<Field empty create={{ label: createLabel, onCreate }} />);
    fireEvent.click(screen.getByLabelText('Client'));
    fireEvent.click(screen.getByRole('option', { name: 'New client' }));
    expect(onCreate).toHaveBeenCalledExactlyOnceWith('');
    expect(screen.getByLabelText('Client').textContent).toBe(
        'Choose an option',
    );
});

it('filters grouped options by secondary text and shows it as a second line', () => {
    render(
        <Combobox
            id="site"
            value=""
            onValueChange={vi.fn()}
            options={[
                {
                    value: '1',
                    label: 'Website',
                    secondary: 'Acme · example.org',
                    group: 'Europe',
                },
                {
                    value: '2',
                    label: 'Website',
                    secondary: 'Beta · example.net',
                    group: 'Europe',
                },
            ]}
        />,
    );
    fireEvent.click(screen.getByRole('combobox'));
    expect(screen.getByText('Europe')).toBeTruthy();
    expect(screen.getByText('Acme · example.org')).toBeTruthy();
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        { target: { value: 'beta example.net' } },
    );
    expect(screen.getAllByRole('option')).toHaveLength(1);
    expect(screen.getByText('Beta · example.net')).toBeTruthy();
});

it('multi picker toggles with checkmarks, stays open and removes via chips', () => {
    const onValuesChange = vi.fn();
    const options = [
        { value: '1', label: 'Mail A' },
        { value: '2', label: 'Mail B' },
    ];
    const view = render(
        <>
            <p id="dest-label">Tell</p>
            <MultiCombobox
                id="dest"
                labelledBy="dest-label"
                options={options}
                values={['1']}
                onValuesChange={onValuesChange}
            />
        </>,
    );
    expect(screen.getByRole('combobox').getAttribute('aria-labelledby')).toBe(
        'dest-label',
    );
    fireEvent.click(screen.getByRole('combobox'));
    fireEvent.click(screen.getByRole('option', { name: /Mail B/ }));
    expect(onValuesChange).toHaveBeenCalledWith(['1', '2']);
    expect(
        screen.getByRole('combobox', { name: 'combobox.search' }),
    ).toBeTruthy();
    view.rerender(
        <>
            <p id="dest-label">Tell</p>
            <MultiCombobox
                id="dest"
                labelledBy="dest-label"
                options={options}
                values={['1', '2']}
                onValuesChange={onValuesChange}
            />
        </>,
    );
    // Chip remove buttons keep a 24 px hit area.
    expect(
        screen
            .getAllByRole('button', { name: 'combobox.remove' })
            .at(0)
            ?.className.includes('size-6'),
    ).toBe(true);
    fireEvent.click(
        screen.getAllByRole('button', { name: 'combobox.remove' }).at(0)!,
    );
    expect(onValuesChange).toHaveBeenLastCalledWith(['2']);
});

it('closes the menu and requests the related-record sheet without changing selection', () => {
    const onCreate = vi.fn();
    render(<Field create={{ label: createLabel, onCreate }} />);
    fireEvent.keyDown(screen.getByLabelText('Client'), { key: 'ArrowDown' });
    const search = screen.getByRole('combobox', { name: 'combobox.search' });
    fireEvent.change(search, { target: { value: 'New Studio' } });
    fireEvent.keyDown(search, { key: 'ArrowDown' });
    fireEvent.keyDown(search, { key: 'Enter' });
    expect(onCreate).toHaveBeenCalledExactlyOnceWith('New Studio');
    expect(screen.getByLabelText('Client').textContent).toBe('Existing Studio');
    expect(
        screen.queryByRole('combobox', { name: 'combobox.search' }),
    ).toBeNull();
});

it('shows a selected option on one line in the trigger: mark + label, the secondary line only in the list and tooltip', () => {
    render(
        <Combobox
            id="site"
            value="1"
            onValueChange={vi.fn()}
            options={[
                {
                    value: '1',
                    label: 'API',
                    secondary: 'Kestrel Mobility · api.example',
                    mark: <span data-testid="mark" />,
                },
            ]}
        />,
        { wrapper: TooltipProvider },
    );
    const trigger = screen.getByRole('combobox');
    expect(within(trigger).getByTestId('mark')).toBeTruthy();
    const label = within(trigger).getByText('API');
    expect(label.className).toContain('truncate');
    // The secondary text is only the tooltip's screen-reader copy, never a visible second line.
    const secondary = within(trigger).getByText(/Kestrel Mobility/);
    expect(secondary.className).toContain('sr-only');
    fireEvent.click(trigger);
    const option = screen.getByRole('option');
    expect(
        within(option).getByText('Kestrel Mobility · api.example').className,
    ).not.toContain('sr-only');
    expect(within(option).getByTestId('mark')).toBeTruthy();
});
