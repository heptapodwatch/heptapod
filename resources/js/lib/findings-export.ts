export type FindingsExportRow = {
    url: string;
    result: string;
    foundOn?: string | null;
    linkText?: string | null;
    since?: string | null;
    isNew?: boolean;
};

/** A second finding group can label its results without changing the primary table. */
export type FindingsExportGroup = {
    rows: FindingsExportRow[];
    resultLabel?: string;
    notVerifiable?: boolean;
};

/** Stable paste/CSV dates in the displayed timezone, independent of the UI language. */
export function findingsTimestamp(value: string, timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(value));
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((part) => part.type === type)!.value;
    return `${part('year')}-${part('month')}-${part('day')} ${part('hour')}:${part('minute')} ${timezone}`;
}
