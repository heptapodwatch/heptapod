import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { useState } from 'react';
import { I18nextProvider } from 'react-i18next';
import { afterEach, beforeEach, expect, it } from 'vitest';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import i18n from '@/lib/i18n';

beforeEach(() => i18n.changeLanguage('en'));
afterEach(cleanup);

/** A toolbar with one fixed and one growable filter, both defaulting to "all", wired like the list pages. */
function Toolbar({ check = 'all', client = 'all' }) {
    const [filters, setFilters] = useState({ check, client });
    const change = (changes: Partial<typeof filters>) =>
        setFilters((current) => ({ ...current, ...changes }));
    return (
        <I18nextProvider i18n={i18n}>
            <FilterBar
                activeCount={0}
                filtersActive={
                    filters.check !== 'all' || filters.client !== 'all'
                }
                onClearFilters={() => change({ check: 'all', client: 'all' })}
            >
                <FilterCombobox
                    ariaLabel="Client"
                    options={[
                        { value: 'all', label: 'All clients' },
                        { value: '4', label: 'Acme' },
                    ]}
                    value={filters.client}
                    onValueChange={(value) => change({ client: value })}
                    onClear={
                        filters.client !== 'all'
                            ? () => change({ client: 'all' })
                            : null
                    }
                />
                <Select
                    value={filters.check}
                    onValueChange={(value) => change({ check: value })}
                >
                    <FilterSelectTrigger
                        aria-label="Check"
                        onClear={
                            filters.check !== 'all'
                                ? () => change({ check: 'all' })
                                : null
                        }
                    >
                        <SelectValue />
                    </FilterSelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All checks</SelectItem>
                        <SelectItem value="uptime">Uptime</SelectItem>
                    </SelectContent>
                </Select>
            </FilterBar>
        </I18nextProvider>
    );
}

it('shows no clear controls while every filter is at its default', () => {
    render(<Toolbar />);
    expect(screen.queryByRole('button', { name: /^Clear/ })).toBeNull();
});

it('resets only the cleared filter from the × inside an active control, without opening its menu', async () => {
    render(<Toolbar check="uptime" client="4" />);
    const clearCheck = screen.getByRole('button', {
        name: 'Clear Check filter',
    });
    expect(
        screen.getByRole('button', { name: 'Clear Client filter' }),
    ).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Clear filters' })).toBeTruthy();

    // A real button in the tab order, outside the trigger it sits on.
    expect(clearCheck.tabIndex).toBe(0);
    expect(
        screen.getByRole('combobox', { name: 'Check' }).contains(clearCheck),
    ).toBe(false);

    await act(async () => {
        fireEvent.click(clearCheck);
    });

    expect(screen.queryByRole('listbox')).toBeNull();
    expect(screen.getByRole('combobox', { name: 'Check' }).textContent).toBe(
        'All checks',
    );
    expect(
        screen.queryByRole('button', { name: 'Clear Check filter' }),
    ).toBeNull();
    // The other filter keeps its value and its own ×; Clear filters stays while it is active.
    expect(
        screen.getByRole('combobox', { name: 'Client' }).textContent,
    ).toContain('Acme');
    expect(
        screen.getByRole('button', { name: 'Clear Client filter' }),
    ).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Clear filters' })).toBeTruthy();
});

it('Clear filters resets every filter and then disappears', async () => {
    render(<Toolbar check="uptime" client="4" />);

    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    });

    expect(screen.queryByRole('button', { name: /^Clear/ })).toBeNull();
    expect(screen.getByRole('combobox', { name: 'Check' }).textContent).toBe(
        'All checks',
    );
});
