import {
    filterDefaults,
    filterValues,
    filtersActive,
    ListFilterBar,
} from '@/components/list-filter-bar';
import { TruncatedText } from '@/components/truncated-text';
import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import { ClientMark } from '@/components/client-mark';
import { Fragment } from 'react';
import { useLiveReload } from '@/hooks/use-live';
import { SiteAvatar } from '@/components/site-avatar';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { ListFooter } from '@/components/list-footer';
import { EmptyState } from '@/components/empty-state';
import { clientComboboxOptions } from '@/components/entity-combobox';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { ListHeader, ListRow } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { calendarDay } from '@/lib/date-time';
import { calendarGroup } from '@/lib/upcoming-calendar';
import { index as upcomingIndex } from '@/routes/upcoming';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';

type Item = Heptapod.Data.Ui.UpcomingItemData;

export default function Upcoming({
    items,
    next_page_url,
    previous_page_url,
    per_page,
    filters,
    clients,
    calendar_now,
}: Heptapod.Data.Ui.UpcomingIndexPageData) {
    const values = filterValues(filters);
    const { t, i18n } = useTranslation();
    const { timezone } = usePage().props;
    const format = useFormat();
    useLiveReload(
        ['state.changed'],
        [
            'items',
            'next_page_url',
            'previous_page_url',
            'clients',
            'calendar_now',
        ],
    );
    const today = calendarDay(calendar_now, timezone);
    const groups = new Map<string, Map<number, Item[]>>();
    for (const item of items) {
        const group = calendarGroup(calendarDay(item.date, timezone), today);
        const day = calendarDay(item.date, timezone);
        const section = groups.get(group) ?? new Map<number, Item[]>();
        section.set(day, [...(section.get(day) ?? []), item]);
        groups.set(group, section);
    }
    const relative = (day: number) =>
        new Intl.RelativeTimeFormat(i18n.language, { numeric: 'auto' }).format(
            Math.round((day - today) / 86_400_000),
            'day',
        );
    const apply = (changes: Record<string, string | null>) =>
        router.get(
            upcomingIndex.url(),
            {
                ...Object.fromEntries(
                    Object.entries(values).filter(
                        ([, value]) => value !== null,
                    ),
                ),
                ...changes,
            },
            { preserveState: true, preserveScroll: true },
        );
    return (
        <PageContainer>
            <Head title={t('Upcoming')} />
            <PageHeader
                title={t('Upcoming')}
                description={t('pages.upcoming.description')}
            />
            <ListFilterBar
                filters={filters}
                sources={{ clients: clientComboboxOptions(clients) }}
                onChange={apply}
                onClearFilters={() => apply(filterDefaults(filters))}
            />
            {values.days === 'all' && (
                <p className="text-xs text-muted-foreground">
                    {t('Up to 12 months ahead.')}
                </p>
            )}
            {items.length === 0 ? (
                <EmptyState
                    icon={CalendarDays}
                    title={t('Nothing scheduled in the next 90 days.')}
                    description={t(
                        'Certificate and domain expiry, maintenance windows and scheduled tasks appear here.',
                    )}
                    filtered={{
                        active: filtersActive(filters),
                        title: t('Nothing scheduled in this range.'),
                        description: t(
                            'Clear filters to see the next 90 days.',
                        ),
                    }}
                />
            ) : (
                [...groups].map(([group, dates]) => (
                    <section
                        key={group}
                        aria-labelledby={`calendar-${group.replaceAll(' ', '-')}`}
                    >
                        <SectionHeading
                            id={`calendar-${group.replaceAll(' ', '-')}`}
                            className="mb-3"
                        >
                            {group === 'This week' || group === 'Next week'
                                ? t(group)
                                : format.date(`${group}-01T12:00:00Z`, {
                                      month: 'long',
                                      year: 'numeric',
                                      timeZone: 'UTC',
                                  })}
                        </SectionHeading>
                        <div className="overflow-hidden rounded-lg border">
                            {[...dates].map(([day, datedItems]) => (
                                <Fragment key={day}>
                                    <ListHeader className="flex">
                                        <AbsoluteTime
                                            value={new Date(day).toISOString()}
                                            mode="calendarDate"
                                            timezone={'UTC'}
                                            className="font-mono tabular-nums"
                                        />
                                        <span>{relative(day)}</span>
                                    </ListHeader>
                                    {datedItems.map((item) => (
                                        <ListRow
                                            key={item.key}
                                            className={rowTargetClass}
                                            onClick={openRowTarget(item.url)}
                                        >
                                            <div className="flex min-w-0 items-center gap-2">
                                                <EntityLink
                                                    name={
                                                        item.type ===
                                                        'maintenance'
                                                            ? (item.target_label ??
                                                              t(
                                                                  'Target unavailable',
                                                              ))
                                                            : (item.site_name ??
                                                              t(
                                                                  'Target unavailable',
                                                              ))
                                                    }
                                                    href={item.url}
                                                    icon={
                                                        item.site_name ? (
                                                            <SiteAvatar
                                                                name={
                                                                    item.site_name
                                                                }
                                                                iconUrl={
                                                                    item.icon_url
                                                                }
                                                                iconBacking={
                                                                    item.icon_backing
                                                                }
                                                            />
                                                        ) : undefined
                                                    }
                                                    tooltip={
                                                        <span className="font-mono tabular-nums">
                                                            {
                                                                format.monitorTime(
                                                                    item.date,
                                                                ).exact
                                                            }
                                                        </span>
                                                    }
                                                />
                                                <span className="flex min-w-0 items-center gap-2 truncate text-xs text-muted-foreground">
                                                    {!item.site_name &&
                                                        item.target_type ===
                                                            'client' &&
                                                        clients
                                                            .filter(
                                                                (client) =>
                                                                    client.id ===
                                                                    item.target_id,
                                                            )
                                                            .map((client) => (
                                                                <ClientMark
                                                                    key={
                                                                        client.id
                                                                    }
                                                                    client={
                                                                        client
                                                                    }
                                                                    small
                                                                />
                                                            ))}
                                                    {item.type ===
                                                        'maintenance' &&
                                                    item.target_type !== 'site'
                                                        ? item.site_count !==
                                                              null &&
                                                          t('{{count}} sites', {
                                                              count: item.site_count,
                                                              formattedCount:
                                                                  format.number(
                                                                      item.site_count,
                                                                  ),
                                                          })
                                                        : item.client_name}
                                                </span>
                                            </div>
                                            <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                                                <AbsoluteTime
                                                    value={item.date}
                                                    mode="time"
                                                    className="shrink-0 font-mono tabular-nums"
                                                />
                                                <span className="shrink-0">
                                                    {t(item.type)}
                                                </span>
                                                <TruncatedText
                                                    content={item.detail}
                                                >
                                                    <span className="min-w-0 truncate">
                                                        {item.detail}
                                                    </span>
                                                </TruncatedText>
                                            </div>
                                        </ListRow>
                                    ))}
                                </Fragment>
                            ))}
                        </div>
                    </section>
                ))
            )}
            {items.length > 0 && (
                <ListFooter
                    count={t('upcoming.count', {
                        count: items.length,
                        formattedCount: format.number(items.length),
                    })}
                    previous={
                        previous_page_url ? { href: previous_page_url } : null
                    }
                    next={next_page_url ? { href: next_page_url } : null}
                    pageSize={per_page}
                />
            )}
        </PageContainer>
    );
}
