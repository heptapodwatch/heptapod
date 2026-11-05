import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { formatDateTime } from '@/lib/date-time';
import { formatMonitorTime, type MonitoringTimeMode } from '@/lib/monitor-time';
import { formatUptimePercentage } from '@/lib/uptime-percentage';

// TypeScript's Intl declarations do not yet include the native DurationFormat API.
const { DurationFormat } = Intl as typeof Intl & {
    DurationFormat: new (
        locale: string,
        options: {
            style: 'narrow' | 'short';
            hours?: 'short';
            hoursDisplay?: 'auto' | 'always';
            minutesDisplay?: 'auto' | 'always';
            secondsDisplay?: 'auto' | 'always';
        },
    ) => { format(duration: Record<string, number>): string };
};

export function useFormat() {
    const { i18n } = useTranslation();
    const { timezone } = usePage().props;
    const number = (value: number, options?: Intl.NumberFormatOptions) =>
        new Intl.NumberFormat(i18n.language, options).format(value);
    const milliseconds = (value: number) =>
        number(value, { maximumFractionDigits: 0 });
    const responseTime = (value: number) =>
        value < 1000
            ? `${milliseconds(value)} ${i18n.t('ms')}`
            : `${number(value / 1000, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} s`;
    const date = (value: string, options?: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat(i18n.language, {
            timeZone: timezone,
            hourCycle: 'h23',
            ...(options ?? { dateStyle: 'medium' }),
        }).format(new Date(value));
    const timestamp = (value: string, displayTimezone = timezone) =>
        formatDateTime(value, i18n.language, displayTimezone, 'timestamp');
    const dateRange = (
        from: string,
        to: string,
        options?: Intl.DateTimeFormatOptions,
    ) =>
        new Intl.DateTimeFormat(i18n.language, {
            timeZone: timezone,
            hourCycle: 'h23',
            ...options,
        }).formatRange(new Date(from), new Date(to));
    const duration = (seconds: number) => {
        const units = [
            [86400, 'days'],
            [3600, 'hours'],
            [60, 'minutes'],
            [1, 'seconds'],
        ] as const;
        let rest = Math.round(seconds);
        const values = Object.fromEntries(
            units
                .flatMap(([size, unit]) => {
                    const value = Math.floor(rest / size);
                    rest %= size;
                    return value ? [[unit, value]] : [];
                })
                .slice(0, 2),
        );
        const zero = Object.keys(values).length === 0;
        return new DurationFormat(i18n.language, {
            style: 'narrow',
            ...(i18n.language.startsWith('de')
                ? { hours: 'short' as const }
                : {}),
            hoursDisplay: 'auto',
            minutesDisplay: 'auto',
            secondsDisplay: zero ? 'always' : 'auto',
        }).format(zero ? { seconds: 0 } : values);
    };
    /** A check interval in short units ("1 min", "1 hr", "1 day"; DE "1 Min.", "1 Std.", "1 Tg."). */
    const intervalDuration = (seconds: number) => {
        const units = [
            [86400, 'days'],
            [3600, 'hours'],
            [60, 'minutes'],
            [1, 'seconds'],
        ] as const;
        let rest = Math.round(seconds);
        const values = Object.fromEntries(
            units.flatMap(([size, unit]) => {
                const value = Math.floor(rest / size);
                rest %= size;
                return value ? [[unit, value]] : [];
            }),
        );
        return new DurationFormat(i18n.language, { style: 'short' }).format(
            values,
        );
    };
    const intervalPhrase = (seconds: number) => {
        const units = [
            [86400, 'days'],
            [3600, 'hours'],
            [60, 'minutes'],
            [1, 'seconds'],
        ] as const;
        const unit = units.find(([size]) => seconds >= size) ?? units[3];
        return seconds % unit[0] === 0
            ? i18n.t(`checks.interval.${unit[1]}`, {
                  count: seconds / unit[0],
                  formattedCount: number(seconds / unit[0]),
              })
            : i18n.t('checks.interval.mixed', {
                  interval: intervalDuration(seconds),
              });
    };
    /** Table cells: the largest unit only ("26m" / "26 Min."); pair it with `duration` in a tooltip for the exact value. */
    const compactDuration = (seconds: number) => {
        const [unit, size] = (
            [
                ['days', 86400],
                ['hours', 3600],
                ['minutes', 60],
                ['seconds', 1],
            ] as const
        ).find(([, size]) => Math.round(seconds) >= size) ?? ['seconds', 1];
        const value = Math.floor(Math.round(seconds) / size);
        return new DurationFormat(i18n.language, {
            style: 'narrow',
            ...(i18n.language.startsWith('de')
                ? { hours: 'short' as const }
                : {}),
            hoursDisplay: 'auto',
            minutesDisplay: 'auto',
            secondsDisplay: value === 0 ? 'always' : 'auto',
        }).format({ [unit]: value });
    };
    // Keep measurement rounding in sync with app/Contracts/SummaryParameters.php.
    // Summary dates are calendar dates; HTTP status codes are identifiers, not measurements.
    const summaryParams = (
        params: Heptapod.Data.Ui.CheckStripData['summary_params'],
    ) =>
        Object.fromEntries(
            Object.entries(params).map(([key, value]) => [
                key,
                key === 'date' && typeof value === 'string'
                    ? date(value, { dateStyle: 'medium', timeZone: 'UTC' })
                    : typeof value === 'number' && key !== 'status'
                      ? key === 'duration'
                          ? duration(value)
                          : key === 'recovery_below'
                            ? milliseconds(Math.floor(value))
                            : key === 'minimum_increase' ||
                                key.endsWith('_percentage')
                              ? number(value, {
                                    minimumFractionDigits: 1,
                                    maximumFractionDigits: 1,
                                })
                              : [
                                      'average',
                                      'median',
                                      'baseline',
                                      'p90',
                                      'delta',
                                      'relative_threshold',
                                  ].includes(key)
                                ? milliseconds(value)
                                : number(value)
                      : value,
            ]),
        );
    const monitorTime = (
        value: string,
        mode: MonitoringTimeMode = 'relative',
        now = Date.now(),
    ) => formatMonitorTime(value, i18n.language, timezone, now, mode);
    const rawSummary = (
        message: string,
        params: Heptapod.Data.Ui.CheckStripData['summary_params'],
    ) =>
        i18n.t(message, {
            ...summaryParams(params),
            ...(message === '{{count}} insecure resources' &&
            typeof params.count === 'number'
                ? {
                      count: params.count,
                      formattedCount: number(params.count),
                  }
                : {}),
        });
    const presentationDate = (
        part: Heptapod.Contracts.CheckRunPresentationPartData,
    ): string | null =>
        (['crawl.collection_reused', 'performance.no_data_while_down'].includes(
            part.key,
        ) ||
            part.key.startsWith('freshness.suppressed.')) &&
        typeof part.params.date === 'string'
            ? part.params.date
            : null;
    const rawPresentationPart = (
        part: Heptapod.Contracts.CheckRunPresentationPartData,
        continuation = false,
    ) => {
        const date = presentationDate(part);
        const params = summaryParams(part.params);
        const lists = { ...part.lists };
        // Retained presentations from before list metadata used comma-separated types.
        if (
            part.key.startsWith('dns.') &&
            typeof params.types === 'string' &&
            !lists.types
        ) {
            lists.types = params.types.split(', ');
        }
        for (const [name, values] of Object.entries(lists)) {
            if (name !== 'types' || !part.key.startsWith('dns.')) {
                throw new Error(
                    'Presentation list has no localized formatter.',
                );
            }
            const last = values.at(-1) ?? '';
            params[name] =
                values.length < 2
                    ? last
                    : values
                          .slice(0, -1)
                          .join(i18n.t('dns.record_types.separator')) +
                      i18n.t('dns.record_types.last_separator') +
                      last;
        }
        return i18n
            .t(part.key, {
                ...params,
                ...(date === null ? {} : { date: timestamp(date) }),
                ...Object.fromEntries(
                    Object.entries(part.labels).map(([name, key]) => [
                        name,
                        i18n.t(
                            key,
                            typeof part.params.count === 'number'
                                ? { count: part.params.count }
                                : {},
                        ),
                    ]),
                ),
                ...(continuation && part.labels.subject ? { subject: '' } : {}),
                ...(typeof part.params.count === 'number'
                    ? {
                          count: part.params.count,
                          formattedCount: number(part.params.count),
                      }
                    : {}),
            })
            .replace(/\.$/, '');
    };
    const rawPresentation = (
        presentation: Heptapod.Contracts.CheckRunPresentationData,
    ) => {
        const reasons = presentation.parts
            .slice(0, presentation.headline_count)
            .map((part, index) => rawPresentationPart(part, index > 0));
        if (presentation.more_reasons > 0) {
            reasons.push(
                i18n.t('checks.presentation.more', {
                    count: presentation.more_reasons,
                    formattedCount: number(presentation.more_reasons),
                }),
            );
        }
        return `${reasons.join(', ')}.`;
    };
    const contextFreeHeadline = (
        check: Heptapod.Contracts.CheckKey,
        headline: string,
        presentation: Heptapod.Contracts.CheckRunPresentationData | null,
    ) =>
        presentation?.names_check
            ? headline
            : i18n.t('{{check}}: {{headline}}', {
                  check: i18n.t(`checks.${check}.label`),
                  headline,
              });
    return {
        number,
        uptimePercentage: (percentage: number, precision = 2) =>
            formatUptimePercentage(percentage, i18n.language, precision),
        milliseconds,
        responseTime,
        date,
        compactDuration,
        intervalPhrase,
        timestamp,
        exact: (value: string, displayTimezone = timezone) =>
            formatDateTime(value, i18n.language, displayTimezone, 'exact'),
        calendarDate: (value: string, displayTimezone = timezone) =>
            formatDateTime(value, i18n.language, displayTimezone, 'date'),
        time: (value: string, displayTimezone = timezone) =>
            formatDateTime(value, i18n.language, displayTimezone, 'time'),
        eventTime: (value: string, displayTimezone = timezone) =>
            formatDateTime(value, i18n.language, displayTimezone, 'eventTime'),
        eventTimestamp: (value: string, displayTimezone = timezone) =>
            formatDateTime(
                value,
                i18n.language,
                displayTimezone,
                'eventTimestamp',
            ),
        dateRange,
        historyRange: (from: string, to: string) => {
            const day = (value: string) =>
                date(value, {
                    year: 'numeric',
                    month: 'numeric',
                    day: 'numeric',
                });
            const today = day(new Date().toISOString());
            if (day(from) === day(to)) {
                const label =
                    day(to) === today
                        ? i18n.t('Today')
                        : date(to, { month: 'short', day: 'numeric' });
                return `${label} · ${dateRange(from, to, { hour: '2-digit', minute: '2-digit', second: Date.parse(to) - Date.parse(from) < 120000 ? '2-digit' : undefined, hourCycle: 'h23' })}`;
            }
            return day(to) === today
                ? `${date(from, { month: 'short', day: 'numeric' })} – ${i18n.t('Today')}`
                : dateRange(from, to, { month: 'short', day: 'numeric' });
        },
        duration,
        summaryParams,
        monitorTime,
        presentationPart: (
            part: Heptapod.Contracts.CheckRunPresentationPartData,
        ) => rawPresentationPart(part),
        presentation: rawPresentation,
        presentationTime: (
            parts: Heptapod.Contracts.CheckRunPresentationPartData[],
        ) => {
            const dates = parts.flatMap((part) => {
                const value = presentationDate(part);
                return value === null
                    ? []
                    : [formatDateTime(value, i18n.language, timezone, 'exact')];
            });
            return dates.length === 0 ? undefined : dates.join(' · ');
        },
        contextFreeHeadline,
        issueHeadline: (
            facts: Pick<
                Heptapod.Data.Ui.IncidentData,
                'check' | 'presentation' | 'dependency_headline_key'
            > & { fallback: string },
            withoutCheckContext = false,
        ) => {
            const overridden = facts.dependency_headline_key !== null;
            const headline = facts.dependency_headline_key
                ? i18n.t(facts.dependency_headline_key)
                : facts.presentation
                  ? rawPresentation(facts.presentation)
                  : facts.fallback;
            return withoutCheckContext
                ? contextFreeHeadline(
                      facts.check,
                      headline,
                      overridden ? null : facts.presentation,
                  )
                : headline;
        },
        summary: rawSummary,
        relative: (value: string, now = Date.now()) =>
            monitorTime(value, 'relative', now).label,
    };
}
