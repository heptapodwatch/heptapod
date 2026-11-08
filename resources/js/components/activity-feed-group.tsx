import { ListFooter } from '@/components/list-footer';
import { AbsoluteTime } from '@/components/absolute-time';
import { PresentationChanges } from '@/components/presentation-changes';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Repeat2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { MonitoringTime, StatusBadge } from '@/components/monitoring';
import { SiteAvatar } from '@/components/site-avatar';
import { Button } from '@/components/ui/button';
import { ListRow, tableRowStyle } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { useFormat } from '@/hooks/use-format';
import { checkTypes } from '@/lib/check-types';
import {
    eventLabel,
    eventState,
    resultState,
    runState,
    statusLabels,
} from '@/lib/status';
import { index as activityIndex } from '@/routes/activity';
import { show as siteShow } from '@/routes/sites';
import { show as incidentShow } from '@/routes/incidents';
import { EntityLink } from '@/components/entity-link';
import { TextLink } from '@/components/text-link';

/** An administrative resolution (pause, disable, deletion, move) is Closed, never Recovered. */
function eventBadgeLabel(event: Heptapod.Data.Ui.EventData) {
    if (event.message_params.administrative_resolution === true)
        return 'Closed';
    return event.severity === 'recovered'
        ? 'activity.recovered'
        : eventLabel(event);
}

export function ActivityFeedGroup({
    group,
    filters,
    expanded,
    details,
    eventPage,
    animate,
}: {
    group: Heptapod.Data.Ui.ActivityGroupData;
    filters: Heptapod.Data.Ui.ActivityFilterData;
    expanded: boolean;
    details: Heptapod.Data.Ui.EventData[];
    eventPage: number;
    animate: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const event = group.latest;
    const collapsed = group.event_count > 1;
    const stateLabel = (state: Heptapod.Contracts.CheckResult | null) => {
        if (state === 'succeeded') return t('activity.recovered');
        if (state === null || state === 'pending')
            throw new Error(`Invalid collapsed state: ${state}`);
        return t(
            statusLabels[
                event.check ? runState(event.check, state) : resultState(state)
            ],
        );
    };
    const firstState =
        group.first_state === 'succeeded'
            ? group.second_state
            : group.first_state;
    const secondState =
        group.first_state === 'succeeded'
            ? group.first_state
            : group.second_state;
    const detailHref = (page: number) =>
        activityIndex.url({
            query: { ...filters, group: group.id, event_page: page },
        });
    return (
        <article
            className={cn(
                tableRowStyle,
                'last:border-b-0',
                animate && 'motion-row-in',
            )}
        >
            <ListRow className="sm:grid-cols-[minmax(0,1fr)_11rem] sm:items-start">
                <div className="min-w-0 space-y-2">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <EntityLink
                            name={event.site_name}
                            href={
                                event.site_id === null
                                    ? undefined
                                    : siteShow.url(event.site_id)
                            }
                            icon={
                                <SiteAvatar
                                    name={event.site_name}
                                    iconUrl={event.icon_url}
                                    iconBacking={event.icon_backing}
                                />
                            }
                        />
                        <span className="flex items-center gap-2 text-xs text-muted-foreground">
                            {event.client_name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {event.check
                                ? t(checkTypes[event.check].label)
                                : '—'}
                        </span>
                    </div>
                    {collapsed ? (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Repeat2
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span className="font-medium">
                                {stateLabel(firstState)} ↔{' '}
                                {stateLabel(secondState)}
                            </span>
                            <span className="font-mono text-xs tabular-nums">
                                ×{format.number(group.event_count)}
                            </span>
                            <span className="font-mono text-xs text-muted-foreground">
                                · <AbsoluteTime value={group.started_at} /> –{' '}
                                <AbsoluteTime value={group.ended_at} />
                            </span>
                            <span className="text-xs text-muted-foreground">
                                ·{' '}
                                {t('now {{state}}', {
                                    state: stateLabel(group.current_state),
                                })}
                            </span>
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-start gap-2">
                            <StatusBadge
                                state={eventState(event)}
                                label={eventBadgeLabel(event)}
                            />
                            {event.incident_id ? (
                                <TextLink
                                    className="min-w-0 text-sm break-words"
                                    href={incidentShow.url(event.incident_id)}
                                >
                                    {event.message}
                                </TextLink>
                            ) : (
                                <span className="min-w-0 text-sm break-words">
                                    {event.message}
                                </span>
                            )}
                        </div>
                    )}
                </div>
                <div className="flex items-center justify-between gap-2 sm:flex-col sm:items-end">
                    <PresentationChanges changes={event.changes} />
                    <MonitoringTime
                        value={event.time}
                        className="font-mono text-xs text-muted-foreground"
                    />
                    {collapsed && (
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={
                                    expanded
                                        ? activityIndex.url({
                                              query: { ...filters },
                                          })
                                        : detailHref(1)
                                }
                                preserveScroll
                                preserveState
                                aria-expanded={expanded}
                                aria-controls={`activity-events-${group.id}`}
                            >
                                {expanded ? <ChevronUp /> : <ChevronDown />}
                                {t(expanded ? 'Hide events' : 'Show events')}
                            </Link>
                        </Button>
                    )}
                </div>
            </ListRow>
            {expanded && (
                <div
                    id={`activity-events-${group.id}`}
                    className="border-t bg-muted/20"
                >
                    {details.map((item) => (
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
                                label={eventBadgeLabel(item)}
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
                            count={t('Events {{from}}–{{to}} of {{total}}', {
                                from: format.number((eventPage - 1) * 20 + 1),
                                to: format.number(
                                    Math.min(eventPage * 20, group.event_count),
                                ),
                                total: format.number(group.event_count),
                            })}
                            previous={
                                eventPage > 1
                                    ? {
                                          href: detailHref(eventPage - 1),
                                          preserveState: true,
                                      }
                                    : null
                            }
                            next={
                                eventPage * 20 < group.event_count
                                    ? {
                                          href: detailHref(eventPage + 1),
                                          preserveState: true,
                                      }
                                    : null
                            }
                        />
                    </div>
                </div>
            )}
        </article>
    );
}
