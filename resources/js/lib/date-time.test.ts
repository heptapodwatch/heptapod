import { expect, it } from 'vitest';
import { zonedInstant, zonedWallTime } from '@/lib/date-time';

// The clock is frozen by vitest.config.ts (2026-10-21T12:00:00Z); these conversions read no clock.

it.each([
    // Berlin spring gap: 02:00–03:00 does not exist on 29 March 2026; a gap time moves forward by the gap (03:30 CEST).
    ['Europe/Berlin', '2026-03-29T02:30', '2026-03-29T01:30:00.000Z'],
    // Berlin autumn overlap: 02:30 happens twice on 25 October 2026; the first occurrence (CEST, UTC+2) wins.
    ['Europe/Berlin', '2026-10-25T02:30', '2026-10-25T00:30:00.000Z'],
    // New York spring gap: 02:00–03:00 does not exist on 8 March 2026; 02:30 moves to 03:30 EDT.
    ['America/New_York', '2026-03-08T02:30', '2026-03-08T07:30:00.000Z'],
    // New York autumn overlap: 01:30 happens twice on 1 November 2026; the first occurrence (EDT, UTC−4) wins.
    ['America/New_York', '2026-11-01T01:30', '2026-11-01T05:30:00.000Z'],
    // Ordinary times on both sides of the changes.
    ['Europe/Berlin', '2026-10-03T23:40', '2026-10-03T21:40:00.000Z'],
    ['America/New_York', '2026-12-01T09:00', '2026-12-01T14:00:00.000Z'],
])('zonedInstant(%s, %s) is %s', (timezone, local, instant) => {
    expect(zonedInstant(local, timezone)).toBe(instant);
});

it.each([
    ['2026-02-30T10:00'],
    ['2026-10-03T24:00'],
    ['2026-10-03 23:40'],
    [''],
])('zonedInstant rejects %j', (local) => {
    expect(zonedInstant(local, 'Europe/Berlin')).toBeNull();
});

it.each([
    // Both instants of the Berlin overlap show the same wall time; zonedInstant maps it back to the first.
    ['Europe/Berlin', '2026-10-25T00:30:00Z', '2026-10-25T02:30'],
    ['Europe/Berlin', '2026-10-25T01:30:00Z', '2026-10-25T02:30'],
    // Around the Berlin gap the wall clock jumps from 01:59 to 03:00.
    ['Europe/Berlin', '2026-03-29T00:59:00Z', '2026-03-29T01:59'],
    ['Europe/Berlin', '2026-03-29T01:00:00Z', '2026-03-29T03:00'],
    ['America/New_York', '2026-11-01T05:30:00Z', '2026-11-01T01:30'],
    ['America/New_York', '2026-11-01T06:30:00Z', '2026-11-01T01:30'],
    ['America/New_York', '2026-03-08T07:30:00Z', '2026-03-08T03:30'],
])('zonedWallTime(%s, %s) is %s', (timezone, instant, local) => {
    expect(zonedWallTime(instant, timezone)).toBe(local);
});

it('round-trips every hour of both 2026 Berlin transition days except the gap', () => {
    for (const day of ['2026-03-29', '2026-10-25']) {
        for (let hour = 0; hour < 24; hour++) {
            const local = `${day}T${String(hour).padStart(2, '0')}:15`;
            const instant = zonedInstant(local, 'Europe/Berlin');
            const expected =
                day === '2026-03-29' && hour === 2 ? `${day}T03:15` : local;
            expect(zonedWallTime(instant!, 'Europe/Berlin')).toBe(expected);
        }
    }
});
