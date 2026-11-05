import { ListFooter } from '@/components/list-footer';
import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { AbsoluteTime } from '@/components/absolute-time';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';
import { cn } from '@/lib/utils';
import { SiteAvatar } from '@/components/site-avatar';
import { CircleAlert } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import { clientComboboxOptions } from '@/components/entity-combobox';
import {
    listCardPadding,
    rowEmphasisClass,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/page-header';
import { SegmentedControl } from '@/components/segmented-control';
import { PageContainer } from '@/components/page-container';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/monitoring';
import { PendingChanges } from '@/components/pending-changes';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFormat } from '@/hooks/use-format';
import { useLiveReload } from '@/hooks/use-live';
import { checkTypes } from '@/lib/check-types';
import { incidentState } from '@/lib/status';
import {
    exportMethod as exportIncidents,
    index as incidentsIndex,
    show as incidentShow,
} from '@/routes/incidents';

type Incident = Heptapod.Data.Ui.IncidentData;
type Filter = Heptapod.Data.Ui.IncidentFilterData;
/** The server's default status (IncidentsReader::DefaultStatus): the URL omits it. */
const defaultStatus = 'open';

export default function IncidentsIndex({
    incidents,
    clients,
    filters,
    pagination,
    summary,
}: Heptapod.Data.Ui.IncidentsIndexPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const openCount = usePage().props.overallHealth?.open_incident_count;
    useLiveReload(['state.changed'], ['incidents', 'pagination', 'summary']);
    const buffered = useBufferedChanges(incidents, (incident) => incident.id);
    const apply = (changes: Partial<Filter>) =>
        router.get(
            incidentsIndex.url(),
            {
                filter: Object.fromEntries(
                    Object.entries({ ...filters, ...changes }).filter(
                        ([key, value]) =>
                            value !== null &&
                            value !== '' &&
                            !(key === 'status' && value === defaultStatus),
                    ),
                ),
            },
            { preserveState: true, preserveScroll: true },
        );
    const activeFilter = Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );
    const activeFilterCount = [
        filters.status !== defaultStatus,
        filters.cause,
        filters.check,
        filters.client_id,
        filters.started_after,
        filters.started_before,
        filters.period !== '30d',
    ].filter(Boolean).length;
    const csvUrl = exportIncidents.url({ query: { filter: activeFilter } });
    const clientName = (incident: Incident) =>
        clients.find((client) => client.id === incident.client_id)?.name ??
        t('Client');
    const stateLabel = (incident: Incident) =>
        t(incident.resolved_at ? 'Resolved' : 'incidents.state.open');
    const cause = (incident: Incident) =>
        format.issueHeadline({
            ...incident,
            fallback: incident.summary
                ? format.summary(incident.summary, incident.summary_params)
                : t(
                      incident.cause === 'errored'
                          ? 'Check could not run'
                          : 'Check failed',
                  ),
        });
    const incidentLabel = (incident: Incident) =>
        t('Open incident {{id}} for {{site}}', {
            id: incident.id,
            site: incident.site_name,
        });
    const cells = (incident: Incident, linked = false) => [
        <StatusBadge
            key="state"
            state={incidentState(incident)}
            label={stateLabel(incident)}
        />,
        <span key="site" className="min-w-0">
            <EntityLink
                name={incident.site_name}
                href={linked ? incidentShow.url(incident.id) : undefined}
                insideLink={!linked}
                ariaLabel={linked ? incidentLabel(incident) : undefined}
                className="flex"
                icon={
                    <SiteAvatar
                        name={incident.site_name}
                        iconUrl={incident.icon_url}
                        iconBacking={incident.icon_backing}
                    />
                }
            />
            <span className="mt-1 flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                <TruncatedText
                    label={t('Client')}
                    content={clientName(incident)}
                >
                    <span className="truncate">{clientName(incident)}</span>
                </TruncatedText>
                <span className="shrink-0 font-mono">
                    · #{format.number(incident.id)}
                </span>
            </span>
        </span>,
        <TruncatedText
            key="check"
            label={t('Check')}
            content={t(checkTypes[incident.check].label)}
        >
            <span className="block truncate text-xs text-muted-foreground">
                {t(checkTypes[incident.check].label)}
            </span>
        </TruncatedText>,
        <TruncatedText key="cause" label={t('Cause')} content={cause(incident)}>
            <span className="min-w-0 truncate text-xs text-muted-foreground">
                {cause(incident)}
            </span>
        </TruncatedText>,
        <AbsoluteTime
            key="started"
            value={incident.started_at}
            label={t('Started')}
            className="text-xs text-muted-foreground"
        />,
        <TooltipHint
            key="duration"
            label={t('Duration')}
            content={format.duration(incident.duration_seconds)}
        >
            <span className="font-mono text-xs tabular-nums">
                {format.compactDuration(incident.duration_seconds)}
            </span>
        </TooltipHint>,
    ];
    return (
        <PageContainer>
            <Head title={t('Incidents')} />
            <PageHeader
                title={t('Incidents')}
                description={t('pages.incidents.description')}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={csvUrl} download>
                                {t('Export')}
                            </a>
                        </Button>
                    </>
                }
            />
            <FilterBar
                activeCount={activeFilterCount}
                filtersActive={activeFilterCount > 0}
                onClearFilters={() => router.get(incidentsIndex.url())}
                segmented={
                    <SegmentedControl
                        label={t('Status')}
                        value={filters.status}
                        onValueChange={(status) => apply({ status })}
                        options={(['open', 'resolved', 'all'] as const).map(
                            (value) => ({
                                value,
                                label:
                                    value === 'open' && openCount !== undefined
                                        ? t('Open {{count}}', {
                                              count: openCount,
                                          })
                                        : t(
                                              value === 'open'
                                                  ? 'incidents.state.open'
                                                  : value === 'resolved'
                                                    ? 'Resolved'
                                                    : 'All',
                                          ),
                            }),
                        )}
                    />
                }
            >
                <FilterCombobox
                    ariaLabel={t('Client')}
                    options={[
                        { value: 'all', label: t('All clients') },
                        ...clientComboboxOptions(clients),
                    ]}
                    value={filters.client_id?.toString() ?? 'all'}
                    onValueChange={(value) =>
                        apply({
                            client_id: value === 'all' ? null : Number(value),
                        })
                    }
                    onClear={
                        filters.client_id !== null
                            ? () => apply({ client_id: null })
                            : null
                    }
                />
                <Select
                    value={filters.check ?? 'all'}
                    onValueChange={(value) =>
                        apply({
                            check:
                                value === 'all'
                                    ? null
                                    : (value as Filter['check']),
                        })
                    }
                >
                    <FilterSelectTrigger
                        aria-label={t('Check')}
                        onClear={
                            filters.check !== null
                                ? () => apply({ check: null })
                                : null
                        }
                    >
                        <SelectValue />
                    </FilterSelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{t('All checks')}</SelectItem>
                        {Object.entries(checkTypes).map(([key, check]) => (
                            <SelectItem key={key} value={key}>
                                {t(check.label)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select
                    value={filters.period}
                    onValueChange={(value) => apply({ period: value })}
                >
                    <FilterSelectTrigger
                        aria-label={t('Time range')}
                        onClear={
                            filters.period !== '30d'
                                ? () => apply({ period: '30d' })
                                : null
                        }
                    >
                        <SelectValue />
                    </FilterSelectTrigger>
                    <SelectContent>
                        <SelectItem value="30d">{t('Last 30 days')}</SelectItem>
                        <SelectItem value="all">{t('All time')}</SelectItem>
                    </SelectContent>
                </Select>
            </FilterBar>
            <p className="text-xs text-muted-foreground">
                {t(
                    '30 days: {{count}} incidents · {{duration}} total · median {{median}}',
                    {
                        count: summary.count,
                        formattedCount: format.number(summary.count),
                        duration: format.duration(
                            summary.total_duration_seconds,
                        ),
                        median:
                            summary.median_duration_seconds === null
                                ? '—'
                                : format.duration(
                                      summary.median_duration_seconds,
                                  ),
                    },
                )}
            </p>
            <PendingChanges
                count={buffered.pendingCount}
                apply={buffered.apply}
            />
            {buffered.rows.length === 0 ? (
                <EmptyState
                    icon={CircleAlert}
                    title={t('No open incidents')}
                    description={t(
                        'An incident opens here when a site is down or a check fails.',
                    )}
                    filtered={{
                        active: activeFilterCount > 0,
                        title: t('No matching incidents'),
                        description: t('Clear filters to see open incidents.'),
                    }}
                />
            ) : (
                <>
                    <div className="hidden overflow-hidden rounded-lg border xl:block">
                        <Table className="table-fixed">
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-24">
                                        {t('State')}
                                    </TableHead>
                                    <TableHead className="w-[26%]">
                                        {t('Site')}
                                    </TableHead>
                                    <TableHead className="w-28">
                                        {t('Check')}
                                    </TableHead>
                                    <TableHead>{t('Cause')}</TableHead>
                                    <TableHead className="w-40">
                                        {t('Started')}
                                    </TableHead>
                                    <TableHead className="w-20 text-right">
                                        {t('Duration')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {buffered.rows.map((incident) => (
                                    <TableRow
                                        key={incident.id}
                                        className={cn(
                                            rowTargetClass,
                                            rowEmphasisClass(
                                                incidentState(incident),
                                            ),
                                        )}
                                        onClick={openRowTarget(
                                            incidentShow.url(incident.id),
                                        )}
                                    >
                                        {cells(incident, true).map(
                                            (cell, index) => (
                                                <TableCell
                                                    key={index}
                                                    className={
                                                        index === 5
                                                            ? 'text-right'
                                                            : 'overflow-hidden'
                                                    }
                                                >
                                                    {cell}
                                                </TableCell>
                                            ),
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <div className="divide-y overflow-hidden rounded-lg border xl:hidden">
                        {buffered.rows.map((incident) => (
                            <Link
                                key={incident.id}
                                href={incidentShow.url(incident.id)}
                                prefetch
                                aria-label={incidentLabel(incident)}
                                className={cn(
                                    'grid min-w-0 gap-2',
                                    listCardPadding,
                                    rowTargetClass,
                                    rowEmphasisClass(incidentState(incident)),
                                )}
                            >
                                {cells(incident).map((cell, index) => (
                                    <span key={index} className="min-w-0">
                                        {cell}
                                    </span>
                                ))}
                            </Link>
                        ))}
                    </div>
                </>
            )}
            {pagination.total > 0 && (
                <ListFooter
                    count={t('{{count}} incidents', {
                        count: pagination.total,
                        formattedCount: format.number(pagination.total),
                    })}
                    previous={
                        pagination.previous_page_url
                            ? { href: pagination.previous_page_url }
                            : null
                    }
                    next={
                        pagination.next_page_url
                            ? { href: pagination.next_page_url }
                            : null
                    }
                    page={pagination.current_page}
                    lastPage={pagination.last_page}
                />
            )}
        </PageContainer>
    );
}
