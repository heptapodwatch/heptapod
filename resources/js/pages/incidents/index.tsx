import {
    filterValues,
    filtersActive,
    ListFilterBar,
} from '@/components/list-filter-bar';
import { ListTable, type ListColumn } from '@/components/list-table';
import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { AbsoluteTime } from '@/components/absolute-time';
import { EntityLink } from '@/components/entity-link';
import { SiteAvatar } from '@/components/site-avatar';
import { CircleAlert } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { rowEmphasisClass } from '@/components/ui/table';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { ExportButton, type ExportScope } from '@/components/export-sheet';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/monitoring';
import { PendingChanges } from '@/components/pending-changes';
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
const causeLabels = {
    errored: 'Check could not run',
    failed: 'Check failed',
} satisfies Record<Heptapod.Enums.IncidentCause, string>;
export default function IncidentsIndex({
    incidents,
    clients,
    filters,
    incidents_pagination,
    summary,
}: Heptapod.Data.Ui.IncidentsIndexPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const openCount = usePage().props.overallHealth?.open_incident_count;
    useLiveReload(
        ['state.changed'],
        ['incidents', 'incidents_pagination', 'summary'],
    );
    const buffered = useBufferedChanges(incidents, (incident) => incident.id);
    const values = filterValues(filters);
    const apply = (changes: Record<string, string | null>) =>
        router.get(
            incidentsIndex.url(),
            {
                filter: Object.fromEntries(
                    Object.entries({ ...values, ...changes }).filter(
                        ([key, value]) =>
                            value &&
                            !(
                                key === 'status' &&
                                value === filters.status?.default
                            ),
                    ),
                ),
            },
            { preserveState: true, preserveScroll: true },
        );
    const activeFilter = Object.fromEntries(
        Object.entries(values).filter(([, value]) => value),
    );
    const unfiltered = { status: 'all', period: 'all' };
    const exportScopes: ExportScope[] = [
        ...(incidents_pagination.last_page > 1
            ? [
                  {
                      key: 'page',
                      label: t('export.scope.page', {
                          count: incidents.length,
                      }),
                      count: incidents.length,
                      url: (format: Heptapod.Enums.ExportFormat) =>
                          exportIncidents.url(format, {
                              query: {
                                  filter: activeFilter,
                                  page: incidents_pagination.current_page,
                                  per_page: incidents_pagination.per_page,
                              },
                          }),
                  },
              ]
            : []),
        {
            key: 'matching',
            label: t('export.scope.matching_incidents', {
                count: incidents_pagination.total,
            }),
            count: incidents_pagination.total,
            csvLimited: true,
            url: (format) =>
                exportIncidents.url(format, {
                    query: { filter: activeFilter },
                }),
        },
        // Every filter at its widest value already exports every incident.
        ...(Object.entries(activeFilter).some(
            ([key, value]) =>
                value !== unfiltered[key as keyof typeof unfiltered],
        )
            ? [
                  {
                      key: 'unfiltered',
                      label: t('export.scope.every_incident'),
                      url: (format: Heptapod.Enums.ExportFormat) =>
                          exportIncidents.url(format, {
                              query: { filter: unfiltered },
                          }),
                  },
              ]
            : []),
    ];
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
                : t(causeLabels[incident.cause]),
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
    const stats = t(
        '30 days: {{count}} incidents · {{duration}} total · median {{median}}',
        {
            count: summary.count,
            formattedCount: format.number(summary.count),
            duration: format.duration(summary.total_duration_seconds),
            median:
                summary.median_duration_seconds === null
                    ? '—'
                    : format.duration(summary.median_duration_seconds),
        },
    );
    return (
        <PageContainer>
            <Head title={t('Incidents')} />
            <PageHeader
                title={t('Incidents')}
                // The 30-day record is the header's one line, like the Sites meta line.
                description={stats}
                actions={
                    <ExportButton
                        kind="incidents"
                        variant="default"
                        size="default"
                        scopes={exportScopes}
                    />
                }
            />
            <ListFilterBar
                filters={filters}
                sources={{
                    clients: { kind: 'clients', scope: { browse: true } },
                }}
                count={openCount}
                onChange={apply}
                onClearFilters={() => router.get(incidentsIndex.url())}
            />
            <PendingChanges
                count={buffered.pendingCount}
                apply={buffered.apply}
            />
            <ListTable
                rows={buffered.rows}
                rowKey={(incident) => incident.id}
                rowHref={(incident) => incidentShow.url(incident.id)}
                rowClassName={(incident) =>
                    rowEmphasisClass(incidentState(incident))
                }
                rowProps={(incident) => ({
                    'aria-label': incidentLabel(incident),
                })}
                breakpoint="xl"
                tableClassName="table-fixed"
                columns={(
                    [
                        { id: 'state', header: t('State'), className: 'w-24' },
                        { id: 'site', header: t('Site'), className: 'w-[26%]' },
                        { id: 'check', header: t('Check'), className: 'w-28' },
                        { id: 'cause', header: t('Cause') },
                        {
                            id: 'started',
                            header: t('Started'),
                            className: 'w-40',
                        },
                        {
                            id: 'duration',
                            header: t('Duration'),
                            align: 'end',
                            className: 'w-20',
                        },
                    ] satisfies Omit<ListColumn<Incident>, 'cell'>[]
                ).map((column, index) => ({
                    ...column,
                    cell: (incident: Incident) => cells(incident, true)[index],
                }))}
                card={(incident) =>
                    cells(incident).map((cell, index) => (
                        <span key={index} className="min-w-0">
                            {cell}
                        </span>
                    ))
                }
                empty={
                    <EmptyState
                        icon={CircleAlert}
                        title={t('No open incidents')}
                        description={t(
                            'An incident opens here when a site is down or a check fails.',
                        )}
                        filtered={{
                            active: filtersActive(filters),
                            title: t('No matching incidents'),
                            description: t(
                                'Clear filters to see open incidents.',
                            ),
                        }}
                    />
                }
                count={t('{{count}} incidents', {
                    count: incidents_pagination.total,
                    formattedCount: format.number(incidents_pagination.total),
                })}
                pagination={incidents_pagination}
            />
        </PageContainer>
    );
}
