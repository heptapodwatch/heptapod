import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    TimezoneCombobox,
    timezoneOffset,
    timezoneOptions,
} from './timezone-combobox';

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
    vi.useRealTimers();
});

it('groups zones by region, shows the UTC offset and pins the given zone first', () => {
    const options = timezoneOptions(
        ['Europe/Berlin', 'America/New_York', 'UTC'],
        ['America/New_York'],
    );
    expect(options.at(0)?.value).toBe('America/New_York');
    const berlin = options.find((option) => option.value === 'Europe/Berlin');
    expect(berlin?.group).toBe('Europe');
    expect(berlin?.secondary).toContain('Berlin');
    expect(berlin?.secondary).toMatch(/UTC[+-]\d{2}:\d{2}/);
    expect(timezoneOffset('UTC')).toBe('UTC');
});

it('renders only the passed server list, plus a stored value missing from it', () => {
    render(
        <TimezoneCombobox
            id="tz"
            timezones={['Europe/Berlin', 'Asia/Kolkata']}
            value="Europe/Kiev"
            onValueChange={vi.fn()}
        />,
    );
    const trigger = screen.getByRole('combobox');
    // The stored value is the selected option even though the list omits it.
    expect(trigger.textContent).toContain('Europe/Kiev');
    fireEvent.click(trigger);
    const labels = screen
        .getAllByRole('option')
        .map((option) => option.textContent);
    expect(labels.some((label) => label?.includes('Asia/Kolkata'))).toBe(true);
    expect(labels.some((label) => label?.includes('Asia/Calcutta'))).toBe(
        false,
    );
    expect(labels.some((label) => label?.includes('America/New_York'))).toBe(
        false,
    );
});

it('offers the inherited default first and maps it to null both ways', () => {
    const onValueChange = vi.fn();
    const { rerender } = render(
        <TimezoneCombobox
            id="tz"
            timezones={['Europe/Berlin', 'Asia/Kolkata']}
            value={null}
            onValueChange={onValueChange}
            defaultZone={{ zone: 'Europe/Berlin', source: 'instance' }}
        />,
    );
    const trigger = screen.getByRole('combobox');
    expect(trigger.textContent).toContain('timezone.default');
    fireEvent.click(trigger);
    expect(screen.getAllByRole('option').at(0)?.textContent).toContain(
        'timezone.default',
    );
    fireEvent.click(screen.getByRole('option', { name: /Asia\/Kolkata/ }));
    expect(onValueChange).toHaveBeenLastCalledWith('Asia/Kolkata');
    rerender(
        <TimezoneCombobox
            id="tz"
            timezones={['Europe/Berlin', 'Asia/Kolkata']}
            value="Asia/Kolkata"
            onValueChange={onValueChange}
            defaultZone={{ zone: 'Europe/Berlin', source: 'instance' }}
        />,
    );
    fireEvent.click(screen.getByRole('combobox'));
    fireEvent.click(screen.getByRole('option', { name: /timezone\.default/ }));
    expect(onValueChange).toHaveBeenLastCalledWith(null);
});

it('reports exact summer and winter offsets under a frozen clock', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-07-15T12:00:00Z'));
    expect(timezoneOffset('Europe/Berlin')).toBe('UTC+02:00');
    expect(timezoneOffset('Asia/Kolkata')).toBe('UTC+05:30');
    vi.setSystemTime(new Date('2026-01-15T12:00:00Z'));
    expect(timezoneOffset('Europe/London')).toBe('UTC');
    expect(timezoneOffset('America/New_York')).toBe('UTC-05:00');
});

it('renders reason badges on pinned options and merges identical pinned zones into one option', () => {
    const { rerender } = render(
        <TimezoneCombobox
            id="tz"
            timezones={['Europe/Berlin', 'America/New_York', 'UTC']}
            pinned={[
                { zone: 'America/New_York', label: "Acme's zone" },
                { zone: 'Europe/Berlin', label: 'Your zone' },
            ]}
            value="America/New_York"
            onValueChange={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('combobox'));
    let options = screen.getAllByRole('option');
    expect(options).toHaveLength(3);
    expect(options[0]?.textContent).toContain('America/New_York');
    expect(options[0]?.textContent).toContain("Acme's zone");
    expect(options[1]?.textContent).toContain('Europe/Berlin');
    expect(options[1]?.textContent).toContain('Your zone');
    fireEvent.keyDown(document.activeElement ?? document.body, {
        key: 'Escape',
    });

    // When both pinned reasons resolve to the same zone, one option carries both badges.
    rerender(
        <TimezoneCombobox
            id="tz"
            timezones={['Europe/Berlin', 'America/New_York', 'UTC']}
            pinned={[
                { zone: 'Europe/Berlin', label: "Acme's zone" },
                { zone: 'Europe/Berlin', label: 'Your zone' },
            ]}
            value="Europe/Berlin"
            onValueChange={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('combobox'));
    options = screen.getAllByRole('option');
    expect(options).toHaveLength(3);
    expect(options[0]?.textContent).toContain('Europe/Berlin');
    expect(options[0]?.textContent).toContain("Acme's zone");
    expect(options[0]?.textContent).toContain('Your zone');
});
