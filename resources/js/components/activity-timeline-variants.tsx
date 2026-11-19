// MOCKUP ONLY (branch feat-sites-visual, phase 1, item 8). Remove in phase 2.
import { usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import { EventTimeline } from '@/components/monitoring';
import { ListHeader } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { eventState, statusLabels } from '@/lib/status';
import { calendarDay } from '@/lib/upcoming-calendar';
import type { EventDto } from '@/types/monitoring';

type Group = Heptapod.Data.Ui.ActivityGroupData;
type Row = { group: Group; earlier: EventDto | null };

/**
 * A3: a recovery directly after (newer than) its own failure, same site and check, becomes one row "Failed → Recovered
 * after 3m". Only neighbours on this page merge, so a pair split by the pager stays two rows.
 */
function collapsePairs(groups: Group[]): Row[] {
    const rows: Row[] = [];
    for (let index = 0; index < groups.length; index++) {
        const group = groups[index]!;
        const older = groups[index + 1];
        const merges =
            older !== undefined &&
            group.event_count === 1 &&
            older.event_count === 1 &&
            group.latest.severity === 'recovered' &&
            older.latest.severity !== 'recovered' &&
            older.latest.severity !== 'info' &&
            group.latest.site_id === older.latest.site_id &&
            group.latest.check === older.latest.check;
        rows.push({ group, earlier: merges ? older.latest : null });
        if (merges) index++;
    }
    return rows;
}

/** /activity as the site overview's event timeline. A1 as is, A2 grouped by day, A3 A2 with failure/recovery pairs merged. */
export function ActivityTimeline({
    groups,
    variant,
}: {
    groups: Group[];
    variant: 'a1' | 'a2' | 'a3';
}) {
    const { t, i18n } = useTranslation();
    const format = useFormat();
    const { timezone } = usePage().props;
    const rows =
        variant === 'a3'
            ? collapsePairs(groups)
            : groups.map((group) => ({ group, earlier: null }));
    const byEvent = new Map(rows.map((row) => [row.group.latest.id, row]));
    const lead = (event: EventDto) => {
        const row = byEvent.get(event.id);
        if (row === undefined) return null;
        if (row.earlier !== null)
            return (
                <span className="text-muted-foreground">
                    {t(statusLabels[eventState(row.earlier)])} →{' '}
                </span>
            );
        if (row.group.event_count > 1)
            return (
                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                    ×{format.number(row.group.event_count)}{' '}
                </span>
            );
        return null;
    };
    const timeline = (items: Row[]) => (
        <EventTimeline
            events={items.map((row) => row.group.latest)}
            showSiteName
            showClientName
            clock={variant !== 'a1'}
            lead={lead}
        />
    );
    if (variant === 'a1')
        return <div className="rounded-lg border p-4">{timeline(rows)}</div>;
    const today = calendarDay(new Date().toISOString(), timezone);
    const dayLabel = (day: number) => {
        const label = new Intl.RelativeTimeFormat(i18n.language, {
            numeric: 'auto',
        }).format(Math.round((day - today) / 86_400_000), 'day');
        return (
            label.charAt(0).toLocaleUpperCase(i18n.language) + label.slice(1)
        );
    };
    const days: [number, Row[]][] = [];
    for (const row of rows) {
        const day = calendarDay(row.group.latest.time, timezone);
        const last = days.at(-1);
        if (last?.[0] === day) last[1].push(row);
        else days.push([day, [row]]);
    }
    return (
        <div className="overflow-hidden rounded-lg border">
            {days.map(([day, items]) => (
                <Fragment key={day}>
                    <ListHeader className="flex gap-2">
                        <span>{dayLabel(day)}</span>
                        <AbsoluteTime
                            value={new Date(day).toISOString()}
                            mode="calendarDate"
                            timezone="UTC"
                            className="font-mono tabular-nums"
                        />
                    </ListHeader>
                    <div className="p-4">{timeline(items)}</div>
                </Fragment>
            ))}
        </div>
    );
}
