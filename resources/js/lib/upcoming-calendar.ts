// Calendar days use the viewer's timezone; elapsed 24-hour periods fail across DST.
export function calendarDay(value: string, timezone: string): number {
    const parts = new Intl.DateTimeFormat('en', {
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

export function calendarGroup(day: number, today: number): string {
    const weekday = new Date(today).getUTCDay();
    const monday = today - ((weekday + 6) % 7) * 86_400_000;
    if (day < monday + 7 * 86_400_000) return 'This week';
    if (day < monday + 14 * 86_400_000) return 'Next week';
    return new Date(day).toISOString().slice(0, 7);
}
