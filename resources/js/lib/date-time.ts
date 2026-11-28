import { dateTimeFormat } from '@/lib/format-cache';

const formats = {
    date: { dateStyle: 'medium' },
    timestamp: { dateStyle: 'medium', timeStyle: 'short' },
    exact: { dateStyle: 'full', timeStyle: 'medium' },
    time: { timeStyle: 'short' },
    eventTime: { timeStyle: 'medium' },
    eventTimestamp: { dateStyle: 'medium', timeStyle: 'medium' },
} as const satisfies Record<string, Intl.DateTimeFormatOptions>;

/**
 * The UTC instant of a `YYYY-MM-DDTHH:MM` wall time in an IANA timezone, as ISO string; null when unparsable.
 * An ambiguous wall time (DST overlap) takes its first occurrence; one inside a DST gap moves forward by the gap.
 */
export function zonedInstant(local: string, timezone: string): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(local);
    if (!match) return null;
    const [year, month, day, hour, minute] = match.slice(1).map(Number) as [
        number,
        number,
        number,
        number,
        number,
    ];
    const wall = Date.UTC(year, month - 1, day, hour, minute);
    const date = new Date(wall);
    if (
        date.getUTCMonth() !== month - 1 ||
        date.getUTCDate() !== day ||
        hour > 23
    )
        return null;
    const parts = dateTimeFormat('en-US', {
        timeZone: timezone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
    const wallAt = (instant: number) => {
        const field = (type: string) =>
            Number(
                parts
                    .formatToParts(new Date(instant))
                    .find((part) => part.type === type)?.value,
            );
        return Date.UTC(
            field('year'),
            field('month') - 1,
            field('day'),
            field('hour'),
            field('minute'),
        );
    };
    const dayMs = 86_400_000;
    // The offsets a day before and after bracket any single DST change.
    const before = wall - (wallAt(wall - dayMs) - (wall - dayMs));
    const after = wall - (wallAt(wall + dayMs) - (wall + dayMs));
    const matching = [before, after]
        .filter((instant) => wallAt(instant) === wall)
        .sort((a, b) => a - b);
    return new Date(matching[0] ?? before).toISOString();
}

/** The `YYYY-MM-DDTHH:MM` wall time of an instant in an IANA timezone: the inverse of zonedInstant. */
export function zonedWallTime(value: string, timezone: string): string {
    return dateTimeFormat('sv-SE', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    })
        .format(new Date(value))
        .replace(' ', 'T');
}

export function formatDateTime(
    value: string,
    locale: string,
    timezone: string,
    preset: keyof typeof formats,
): string {
    return dateTimeFormat(locale, {
        timeZone: timezone,
        hourCycle: 'h23',
        ...formats[preset],
    }).format(new Date(value));
}

// Calendar days use the viewer's timezone; elapsed 24-hour periods fail across DST.
export function calendarDay(value: string, timezone: string): number {
    const parts = dateTimeFormat('en', {
        timeZone: timezone,
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
    }).formatToParts(new Date(value));
    const part = (type: Intl.DateTimeFormatPartTypes) => {
        const value = parts.find((part) => part.type === type)?.value;
        if (value === undefined) throw new Error(`Missing calendar ${type}`);
        return Number(value);
    };
    return Date.UTC(part('year'), part('month') - 1, part('day'));
}

/** The readable short form of an IANA zone: its city ("Europe/Berlin" → "Berlin", "America/Argentina/Buenos_Aires" → "Buenos Aires"). */
export function zoneCity(timezone: string): string {
    return (timezone.split('/').at(-1) ?? timezone).replaceAll('_', ' ');
}

/** The browser's IANA zone as the platform reports it; the server validates it against its own list. */
export function browserTimezone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}
