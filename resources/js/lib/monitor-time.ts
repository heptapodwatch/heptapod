import { formatDateTime } from '@/lib/date-time';

export type MonitoringTimeMode = 'relative' | 'since';

export function formatMonitorTime(
    value: string,
    locale: string,
    timezone: string,
    now = Date.now(),
    mode: MonitoringTimeMode = 'relative',
): { label: string; exact: string } {
    const time = new Date(value);
    if (Number.isNaN(time.getTime()))
        throw new RangeError('Invalid monitoring time.');

    const format = (options: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat(locale, { timeZone: timezone, ...options });
    const exact = formatDateTime(value, locale, timezone, 'exact');
    const day = format({ year: 'numeric', month: 'numeric', day: 'numeric' });
    const current = new Date(now);
    if (day.format(time) !== day.format(current)) {
        const otherYear =
            format({ year: 'numeric' }).format(time) !==
            format({ year: 'numeric' }).format(current);
        return {
            label: format({
                month: 'short',
                day: 'numeric',
                ...(otherYear ? { year: 'numeric' } : {}),
            }).format(time),
            exact,
        };
    }
    if (mode === 'since') {
        return {
            label: formatDateTime(value, locale, timezone, 'time'),
            exact,
        };
    }

    const seconds = Math.round((time.getTime() - now) / 1000);
    const unit =
        Math.abs(seconds) >= 3600
            ? 'hour'
            : Math.abs(seconds) >= 60
              ? 'minute'
              : 'second';
    const size = { hour: 3600, minute: 60, second: 1 }[unit];
    return {
        label: new Intl.RelativeTimeFormat(locale, {
            numeric: 'auto',
            style: 'short',
        }).format(Math.round(seconds / size), unit),
        exact,
    };
}
