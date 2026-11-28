export function calendarGroup(day: number, today: number): string {
    const weekday = new Date(today).getUTCDay();
    const monday = today - ((weekday + 6) % 7) * 86_400_000;
    if (day < monday + 7 * 86_400_000) return 'This week';
    if (day < monday + 14 * 86_400_000) return 'Next week';
    return new Date(day).toISOString().slice(0, 7);
}
