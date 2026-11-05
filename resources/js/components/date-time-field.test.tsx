import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { DateTimeField, TimeField } from './ui/date-time-field';

const state = vi.hoisted(() => ({ locale: 'en' }));
vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        i18n: { language: state.locale },
    }),
}));
beforeEach(() => {
    state.locale = 'en';
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-27T12:00:00Z')); // a Tuesday
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
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function Fields() {
    const [time, setTime] = useState('09:30');
    const [at, setAt] = useState('2026-10-27T14:30');
    return (
        <>
            <label htmlFor="time">Time</label>
            <TimeField id="time" value={time} onValueChange={setTime} />
            <output data-testid="time">{time}</output>
            <label htmlFor="at">At</label>
            <DateTimeField
                id="at"
                value={at}
                onValueChange={setAt}
                timezone="UTC"
            />
            <output data-testid="at">{at}</output>
        </>
    );
}

it('emits a complete 24-hour time and keeps invalid typed text, flagged', () => {
    render(<Fields />);
    const time = screen.getByLabelText('Time');
    fireEvent.change(time, { target: { value: '2359' } });
    expect(screen.getByTestId('time').textContent).toBe('23:59');
    fireEvent.change(time, { target: { value: '25:99' } });
    fireEvent.blur(time);
    // Fail loud: the typed text stays and is marked, the value is not replaced.
    expect((time as HTMLInputElement).value).toBe('25:99');
    expect(time.getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByTestId('time').textContent).toBe('23:59');
});

it('keeps an invalid typed datetime, reports it, and accepts DE and EN display forms', () => {
    const invalid: boolean[] = [];
    function Tracked() {
        const [at, setAt] = useState('2026-10-27T14:30');
        return (
            <>
                <label htmlFor="tracked">Tracked</label>
                <DateTimeField
                    id="tracked"
                    value={at}
                    onValueChange={setAt}
                    onInvalidChange={(value) => invalid.push(value)}
                />
                <output data-testid="tracked">{at}</output>
            </>
        );
    }
    render(<Tracked />);
    const at = screen.getByLabelText('Tracked');
    fireEvent.change(at, { target: { value: '32.13.2026 25:99' } });
    fireEvent.blur(at);
    expect((at as HTMLInputElement).value).toBe('32.13.2026 25:99');
    expect(at.getAttribute('aria-invalid')).toBe('true');
    expect(invalid.at(-1)).toBe(true);
    expect(screen.getByTestId('tracked').textContent).toBe('2026-10-27T14:30');
    fireEvent.change(at, { target: { value: '29.03.2026 02:30' } });
    expect(screen.getByTestId('tracked').textContent).toBe('2026-03-29T02:30');
    expect(invalid.at(-1)).toBe(false);
    fireEvent.change(at, { target: { value: 'Oct 5, 2026, 08:15' } });
    expect(screen.getByTestId('tracked').textContent).toBe('2026-10-05T08:15');
});

it('keeps the datetime-local value while typing and picking a calendar day', () => {
    render(<Fields />);
    const at = screen.getByLabelText('At');
    fireEvent.change(at, { target: { value: '2026-11-02 08:15' } });
    expect(screen.getByTestId('at').textContent).toBe('2026-11-02T08:15');
    fireEvent.click(screen.getByRole('button', { name: 'Select date' }));
    fireEvent.click(screen.getByRole('button', { name: 'Next month' }));
    fireEvent.click(
        screen.getByRole('gridcell', { name: 'Thursday, December 24, 2026' }),
    );
    expect(screen.getByTestId('at').textContent).toBe('2026-12-24T08:15');
});

it('moves calendar focus by day and week, by month, and within the week', () => {
    render(<Fields />);
    fireEvent.click(screen.getByRole('button', { name: 'Select date' }));
    const selected = screen.getByRole('gridcell', {
        name: 'Tuesday, October 27, 2026',
    });
    expect(selected.getAttribute('aria-selected')).toBe('true');
    expect(selected.getAttribute('aria-current')).toBe('date');
    expect(document.activeElement).toBe(selected);
    fireEvent.keyDown(selected, { key: 'ArrowRight' });
    expect(document.activeElement).toBe(
        screen.getByRole('gridcell', { name: 'Wednesday, October 28, 2026' }),
    );
    fireEvent.keyDown(document.activeElement!, { key: 'ArrowDown' });
    expect(document.activeElement).toBe(
        screen.getByRole('gridcell', { name: 'Wednesday, November 4, 2026' }),
    );
    fireEvent.keyDown(document.activeElement!, { key: 'Home' });
    expect(document.activeElement).toBe(
        screen.getByRole('gridcell', { name: 'Monday, November 2, 2026' }),
    );
    fireEvent.keyDown(document.activeElement!, { key: 'End' });
    expect(document.activeElement).toBe(
        screen.getByRole('gridcell', { name: 'Sunday, November 8, 2026' }),
    );
    fireEvent.keyDown(document.activeElement!, { key: 'PageUp' });
    expect(document.activeElement).toBe(
        screen.getByRole('gridcell', { name: 'Thursday, October 8, 2026' }),
    );
});

it('starts weeks on Monday with localized German day labels', () => {
    state.locale = 'de';
    render(<Fields />);
    fireEvent.click(screen.getByRole('button', { name: 'Select date' }));
    const headers = screen
        .getAllByRole('columnheader')
        .map((cell) => cell.textContent);
    expect(headers).toEqual(['M', 'D', 'M', 'D', 'F', 'S', 'S']);
    const day = screen.getByRole('gridcell', {
        name: 'Freitag, 2. Oktober 2026',
    });
    expect(day).toBeTruthy();
    // Friday is the fifth column of its week row (Monday first).
    expect(day.parentElement?.children[4]).toBe(day);
});

it('uses a time typed before a date is chosen when a day is picked', () => {
    function Optional() {
        const [at, setAt] = useState('');
        return (
            <>
                <label htmlFor="expiry">Expiry</label>
                <DateTimeField id="expiry" value={at} onValueChange={setAt} />
                <output data-testid="expiry">{at}</output>
            </>
        );
    }
    render(<Optional />);
    fireEvent.click(screen.getByRole('button', { name: 'Select date' }));
    fireEvent.change(screen.getByLabelText('Time'), {
        target: { value: '0930' },
    });
    fireEvent.click(
        screen.getByRole('gridcell', { name: 'Friday, October 30, 2026' }),
    );
    expect(screen.getByTestId('expiry').textContent).toBe('2026-10-30T09:30');
});

it('shows the full timezone while it fits beside the value and the city otherwise, never a cut-off chip', () => {
    // jsdom has no layout: text widths are 8 px per character; the field width is the test's.
    let fieldWidth = 400;
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(
        function (this: HTMLElement) {
            return (this.textContent ?? '').length * 8;
        },
    );
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(
        function (this: HTMLElement) {
            return this.firstElementChild?.tagName === 'INPUT' ? fieldWidth : 0;
        },
    );
    const field = (
        <DateTimeField
            id="at"
            value="2026-10-27T14:30"
            onValueChange={() => {}}
            timezone="Europe/Berlin"
        />
    );
    const { rerender } = render(field);
    const chip = () => document.querySelector('[data-slot="timezone-chip"]')!;
    expect(chip().textContent).toBe('Europe/Berlin');
    expect(chip().className).not.toContain('truncate');
    // 12 + 128 (value) + 4 + 104 (full zone) + 40 = 288 px needed.
    fieldWidth = 280;
    rerender(
        <DateTimeField
            id="at"
            value="2026-10-28T14:30"
            onValueChange={() => {}}
            timezone="Europe/Berlin"
        />,
    );
    expect(chip().textContent).toBe('Berlin');
    // The input reserves the chip's width, so the value never runs under it.
    expect(
        (screen.getByRole('textbox') as HTMLInputElement).style.paddingRight,
    ).toBe(`${'Berlin'.length * 8 + 40 + 4}px`);
});

it('shortens a long nested zone to its city with spaces, and keeps it whole when the field is wide', () => {
    let fieldWidth = 400;
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(
        function (this: HTMLElement) {
            return (this.textContent ?? '').length * 8;
        },
    );
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(
        function (this: HTMLElement) {
            return this.firstElementChild?.tagName === 'INPUT' ? fieldWidth : 0;
        },
    );
    const zone = 'America/Argentina/Buenos_Aires';
    const field = (value: string) => (
        <DateTimeField
            id="at"
            value={value}
            onValueChange={() => {}}
            timezone={zone}
        />
    );
    const { rerender } = render(field('2026-10-27T14:30'));
    const chip = () => document.querySelector('[data-slot="timezone-chip"]')!;
    // 12 + 128 (value) + 4 + 240 (full zone) + 40 = 424 px needed.
    expect(chip().textContent).toBe('Buenos Aires');
    expect(chip().className).not.toContain('truncate');
    fieldWidth = 440;
    rerender(field('2026-10-28T14:30'));
    expect(chip().textContent).toBe(zone);
});
