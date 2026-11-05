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
import { EmptyState } from '@/components/empty-state';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import { clientComboboxOptions } from '@/components/entity-combobox';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { SegmentedControl } from '@/components/segmented-control';
import { ListHeader, ListRow } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { calendarDay, calendarGroup } from '@/lib/upcoming-calendar';
import { index as upcomingIndex } from '@/routes/upcoming';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';

type Item = Heptapod.Data.Ui.UpcomingItemData;

export default function Upcoming({
    items,
    days,
    type,
    clients,
    client_id,
    calendar_now,
}: {
    items: Item[];
    days: string;
    type: string;
    clients: Heptapod.Data.Ui.ClientData[];
    client_id: number | null;
    calendar_now: string;
}) {
    const { t, i18n } = useTranslation();
    const { timezone } = usePage().props;
    const format = useFormat();
    useLiveReload(['state.changed'], ['items', 'clients', 'calendar_now']);
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
    const apply = (
        changes: Partial<{
            days: string;
            type: string;
            client_id: number | null;
        }>,
    ) =>
        router.get(
            upcomingIndex.url(),
            { days, type, client_id: client_id ?? undefined, ...changes },
            { preserveState: true, preserveScroll: true },
        );
    return (
        <PageContainer>
            <Head title={t('Upcoming')} />
            <PageHeader
                title={t('Upcoming')}
                description={t('pages.upcoming.description')}
            />
            <FilterBar
                activeCount={
                    [client_id, type !== 'all', days !== '90'].filter(Boolean)
                        .length
                }
                filtersActive={
                    client_id !== null || type !== 'all' || days !== '90'
                }
                onClearFilters={() =>
                    apply({ days: '90', type: 'all', client_id: null })
                }
                segmented={
                    <SegmentedControl
                        label={t('Time range')}
                        value={days}
                        onValueChange={(days) => apply({ days })}
                        options={[
                            { value: '30', label: t('30 days') },
                            { value: '90', label: t('90 days') },
                            { value: 'all', label: t('All') },
                        ]}
                    />
                }
            >
                <FilterCombobox
                    ariaLabel={t('Client')}
                    options={[
                        { value: 'all', label: t('All clients') },
                        ...clientComboboxOptions(clients),
                    ]}
                    value={client_id?.toString() ?? 'all'}
                    onValueChange={(value) =>
                        apply({
                            client_id: value === 'all' ? null : Number(value),
                        })
                    }
                    onClear={
                        client_id !== null
                            ? () => apply({ client_id: null })
                            : null
                    }
                />
                <Select value={type} onValueChange={(type) => apply({ type })}>
                    <FilterSelectTrigger
                        aria-label={t('Type')}
                        onClear={
                            type !== 'all' ? () => apply({ type: 'all' }) : null
                        }
                    >
                        <SelectValue />
                    </FilterSelectTrigger>
                    <SelectContent>
                        {[
                            ['all', 'All types'],
                            ['certificate', 'Certificates'],
                            ['domain', 'Domains'],
                            ['maintenance', 'Maintenance'],
                            ['task', 'Tasks'],
                        ].map(([value, label]) => (
                            <SelectItem key={value} value={value!}>
                                {t(label!)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FilterBar>
            {days === 'all' && (
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
                        active:
                            client_id !== null ||
                            type !== 'all' ||
                            days !== '90',
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
            {items.length === 100 && (
                <p className="text-xs text-muted-foreground">
                    {t('Showing the first 100 dates.')}
                </p>
            )}
        </PageContainer>
    );
}
