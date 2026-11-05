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

it('reports exact summer and winter offsets under a frozen clock', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-07-15T12:00:00Z'));
    expect(timezoneOffset('Europe/Berlin')).toBe('UTC+02:00');
    expect(timezoneOffset('Asia/Kolkata')).toBe('UTC+05:30');
    vi.setSystemTime(new Date('2026-01-15T12:00:00Z'));
    expect(timezoneOffset('Europe/London')).toBe('UTC');
    expect(timezoneOffset('America/New_York')).toBe('UTC-05:00');
});
