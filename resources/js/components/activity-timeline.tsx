import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import { ListFooter } from '@/components/list-footer';
import { EventTimeline, StatusBadge } from '@/components/monitoring';
import { PresentationChanges } from '@/components/presentation-changes';
import { Button } from '@/components/ui/button';
import { ListHeader, ListRow } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import {
    eventLabel,
    eventState,
    resultState,
    runState,
    statusLabels,
} from '@/lib/status';
import { calendarDay } from '@/lib/upcoming-calendar';
import { index as activityIndex } from '@/routes/activity';
import type { EventDto } from '@/types/monitoring';

type Group = Heptapod.Data.Ui.ActivityGroupData;

/** The partial reload of a flapping group's own events; the timeline and its loaded batches stay as they are. */
const detailProps = ['expanded_id', 'expanded_events', 'expanded_pagination'];

/**
 * Activity as the site overview's event timeline, grouped by calendar day (relative day + date header, the event
 * clock per row). A recovery the server paired with its failure reads "Failed → Recovered after 3m"; a flapping group
 * reads "Failed ↔ Recovered ×N" and expands to its own events. Day headers come from the whole rendered list, so
 * loading another batch never repeats one.
 */
export function ActivityTimeline({
    groups,
    filters,
    expandedId,
    expandedEvents,
    expandedPagination,
}: {
    groups: Group[];
    filters: Heptapod.Data.Ui.ActivityFilterData;
    expandedId: number | null;
    expandedEvents: EventDto[];
    expandedPagination: Heptapod.Contracts.PaginationData | null;
}) {
    const { t, i18n } = useTranslation();
    const format = useFormat();
    const { timezone } = usePage().props;
    const byEvent = new Map(groups.map((group) => [group.latest.id, group]));
    const stateLabel = (
        event: EventDto,
        state: Heptapod.Contracts.CheckResult | null,
    ) => {
        if (state === 'succeeded') return t('activity.recovered');
        if (state === null || state === 'pending')
            throw new Error(`Invalid collapsed state: ${state}`);
        return t(
            statusLabels[
                event.check ? runState(event.check, state) : resultState(state)
            ],
        );
    };
    const lead = (event: EventDto) => {
        const group = byEvent.get(event.id);
        if (group === undefined) return null;
        if (group.earlier !== null)
            return (
                <span className="text-muted-foreground">
                    {t(statusLabels[eventState(group.earlier)])} →{' '}
                </span>
            );
        if (group.event_count > 1) {
            const [first, second] =
                group.first_state === 'succeeded'
                    ? [group.second_state, group.first_state]
                    : [group.first_state, group.second_state];
            return (
                <span className="text-muted-foreground">
                    {stateLabel(event, first)} ↔ {stateLabel(event, second)}{' '}
                    <span className="font-mono text-xs tabular-nums">
                        ×{format.number(group.event_count)}
                    </span>{' '}
                    ·{' '}
                </span>
            );
        }
        return null;
    };
    const detailHref = (groupId: number | null) =>
        activityIndex.url({
            query: {
                ...filters,
                q: filters.q === '' ? undefined : filters.q,
                group: groupId ?? undefined,
            },
        });
    const below = (event: EventDto) => {
        const group = byEvent.get(event.id);
        if (group === undefined || group.event_count < 2) return null;
        const expanded = expandedId === group.id;
        return (
            <div className="space-y-2 pt-1">
                <Button asChild variant="ghost" size="xs">
                    <Link
                        href={detailHref(expanded ? null : group.id)}
                        only={detailProps}
                        preserveScroll
                        preserveState
                        aria-expanded={expanded}
                        aria-controls={`activity-events-${group.id}`}
                    >
                        {expanded ? <ChevronUp /> : <ChevronDown />}
                        {t(expanded ? 'Hide events' : 'Show events')}
                    </Link>
                </Button>
                {expanded && (
                    <div
                        id={`activity-events-${group.id}`}
                        className="overflow-hidden rounded-lg border"
                    >
                        {expandedEvents.map((item) => (
                            <ListRow
                                key={item.id}
                                className="sm:grid-cols-[7rem_8rem_minmax(0,1fr)] sm:items-center"
                            >
                                <AbsoluteTime
                                    value={item.time}
                                    mode="eventTime"
                                    className="font-mono text-xs text-muted-foreground"
                                />
                                <StatusBadge
                                    state={eventState(item)}
                                    label={eventLabel(item)}
                                />
                                <span className="text-xs break-words">
                                    {item.message}
                                    <span className="block">
                                        <PresentationChanges
                                            changes={item.changes}
                                        />
                                    </span>
                                </span>
                            </ListRow>
                        ))}
                        <div className="border-t px-4 py-3">
                            <ListFooter
                                count={t('{{count}} events', {
                                    count: group.event_count,
                                    formattedCount: format.number(
                                        group.event_count,
                                    ),
                                })}
                                pagination={expandedPagination ?? undefined}
                                only={detailProps}
                            />
                        </div>
                    </div>
                )}
            </div>
        );
    };
    const today = calendarDay(new Date().toISOString(), timezone);
    const dayLabel = (day: number) => {
        const label = new Intl.RelativeTimeFormat(i18n.language, {
            numeric: 'auto',
        }).format(Math.round((day - today) / 86_400_000), 'day');
        return (
            label.charAt(0).toLocaleUpperCase(i18n.language) + label.slice(1)
        );
    };
    const days: [number, Group[]][] = [];
    for (const group of groups) {
        const day = calendarDay(group.latest.time, timezone);
        const last = days.at(-1);
        if (last?.[0] === day) last[1].push(group);
        else days.push([day, [group]]);
    }
    return (
        <div
            className="overflow-hidden rounded-lg border"
            data-activity-timeline
        >
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
                    <div className="p-4">
                        <EventTimeline
                            events={items.map((group) => group.latest)}
                            showSiteName
                            showClientName
                            clock
                            lead={lead}
                            below={below}
                        />
                    </div>
                </Fragment>
            ))}
        </div>
    );
}
