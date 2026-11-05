import { expect, test } from 'vitest';
import { formatMonitorTime } from './monitor-time';

test('formats recent times in the user locale and shows an exact 24-hour tooltip', () => {
    const value = '2026-10-04T11:22:00Z';
    const now = Date.parse('2026-10-04T12:00:00Z');

    expect(formatMonitorTime(value, 'en', 'UTC', now)).toEqual({
        label: '38 min. ago',
        exact: 'Sunday, October 4, 2026 at 11:22:00',
    });
    expect(formatMonitorTime(value, 'de', 'UTC', now)).toEqual({
        label: 'vor 38 Min.',
        exact: 'Sonntag, 4. Oktober 2026 um 11:22:00',
    });
});

test('shows a calendar date across the user local-day boundary', () => {
    const value = '2026-10-03T23:30:00Z';
    const now = Date.parse('2026-10-04T00:30:00Z');

    expect(formatMonitorTime(value, 'en', 'UTC', now).label).toBe('Oct 3');
    expect(formatMonitorTime(value, 'en', 'Asia/Kathmandu', now).label).toBe(
        '1 hr. ago',
    );
    expect(formatMonitorTime(value, 'de', 'UTC', now).label).toBe('3. Okt.');
});

test('labels incident start with the local 24-hour clock and retains exact seconds', () => {
    const value = '2026-10-03T14:03:22Z';
    const now = Date.parse('2026-10-03T14:30:00Z');

    expect(
        formatMonitorTime(value, 'en', 'Europe/Berlin', now, 'since'),
    ).toEqual({
        label: '16:03',
        exact: 'Saturday, October 3, 2026 at 16:03:22',
    });
});
