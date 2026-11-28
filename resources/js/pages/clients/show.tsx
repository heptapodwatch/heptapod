import { EntityLink } from '@/components/entity-link';
import { ListTable } from '@/components/list-table';
import { TextLink } from '@/components/text-link';
import { SiteAvatar } from '@/components/site-avatar';
import { PageHeader } from '@/components/page-header';
import { StatTile } from '@/components/stat-tile';
import { PageContainer } from '@/components/page-container';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { isCoalescedStateChange, useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { PendingChanges } from '@/components/pending-changes';
import { useTranslation } from 'react-i18next';
import { SitesTable } from '@/components/sites-table';
import { ClientHeader } from '@/components/client-header';
import { ContentState, StatusBadge } from '@/components/monitoring';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ClientMessagesList } from '@/components/client-messages-list';
import { checkTypes } from '@/lib/check-types';
import { incidentCauseState } from '@/lib/status';
import { useFormat } from '@/hooks/use-format';
import { create as siteCreate, show as siteShow } from '@/routes/sites';
import {
    index as incidentsIndex,
    show as incidentShow,
} from '@/routes/incidents';

export default function ClientDetail({
    client,
    summary,
    sites,
    sites_pagination,
    incidents,
    can_manage,
}: Heptapod.Data.Ui.ClientDetailPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const { url } = usePage();
    const requestedTab = new URLSearchParams(url.split('?')[1]).get('tab');
    const tab =
        requestedTab === 'incidents' || requestedTab === 'messages'
            ? requestedTab
            : 'sites';
    useLiveReload(
        ['state.changed'],
        ['client', 'summary', 'sites', 'sites_pagination', 'incidents'],
        (signal) =>
            signal.ids.client === client.id || isCoalescedStateChange(signal),
    );
    const bufferedSites = useBufferedChanges(sites, (site) => site.id);
    const bufferedIncidents = useBufferedChanges(
        incidents,
        (incident) => incident.id,
    );
    const incidentSiteAvatar = (incident: Heptapod.Data.Ui.IncidentData) => (
        <SiteAvatar
            name={incident.site_name}
            iconUrl={incident.icon_url}
            iconBacking={incident.icon_backing}
        />
    );
    const incidentStatus = (incident: Heptapod.Data.Ui.IncidentData) =>
        incident.resolved_at ? (
            <StatusBadge state="ok" label="Resolved" />
        ) : (
            <StatusBadge state={incidentCauseState(incident)} />
        );
    return (
        <>
            <Head title={client.name} />
            <PageContainer>
                <ClientHeader
                    client={client}
                    section={tab}
                    canManage={can_manage}
                />
                {tab === 'sites' && (
                    <>
                        <PageHeader
                            headingLevel={2}
                            title={t('Sites')}
                            description={t('pages.client.description')}
                            actions={
                                <>
                                    {can_manage && (
                                        <div className="flex gap-2">
                                            {!client.archived_at && (
                                                <Button asChild>
                                                    <Link
                                                        href={siteCreate.url({
                                                            query: {
                                                                client_id:
                                                                    client.id,
                                                            },
                                                        })}
                                                    >
                                                        {t('Add site')}
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </>
                            }
                        />
                        <Card>
                            <CardContent className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {(
                                    [
                                        ['Sites monitored', summary.sites],
                                        ['Currently down', summary.down],
                                        [
                                            'Open incidents',
                                            summary.open_incidents,
                                        ],
                                    ] as const
                                ).map(([label, count]) => (
                                    <StatTile
                                        key={label}
                                        label={t(label)}
                                        value={format.number(count)}
                                    />
                                ))}
                                <StatTile
                                    label={t('30-day uptime')}
                                    value={
                                        summary.uptime === null
                                            ? '—'
                                            : format.uptimePercentage(
                                                  summary.uptime,
                                              )
                                    }
                                />
                            </CardContent>
                        </Card>
                    </>
                )}
                {tab === 'sites' && (
                    <div className="space-y-3">
                        <PendingChanges
                            count={bufferedSites.pendingCount}
                            apply={bufferedSites.apply}
                        />
                        <SitesTable
                            sites={bufferedSites.rows}
                            clients={[client]}
                            showClient={false}
                            onPrefetch={(site) =>
                                router.prefetch(siteShow.url(site.id), {
                                    method: 'get',
                                })
                            }
                            empty={
                                <ContentState
                                    state="empty"
                                    title={
                                        client.archived_at
                                            ? 'No sites for this client.'
                                            : 'Start monitoring your first site'
                                    }
                                    description={
                                        client.archived_at
                                            ? 'Archived clients keep site history.'
                                            : 'Add a URL to begin collecting results.'
                                    }
                                />
                            }
                            count={t('{{count}} sites', {
                                count: sites_pagination.total,
                                formattedCount: format.number(
                                    sites_pagination.total,
                                ),
                            })}
                            pagination={sites_pagination}
                        />
                    </div>
                )}
                {tab === 'incidents' && (
                    <div className="space-y-3">
                        <h2 className="sr-only">{t('Incidents')}</h2>
                        <PendingChanges
                            count={bufferedIncidents.pendingCount}
                            apply={bufferedIncidents.apply}
                        />
                        <ListTable
                            rows={bufferedIncidents.rows}
                            rowKey={(incident) => incident.id}
                            rowHref={(incident) =>
                                incidentShow.url(incident.id)
                            }
                            columns={[
                                {
                                    id: 'incident',
                                    header: t('Incident detail'),
                                    cell: (incident) => (
                                        <TextLink
                                            href={incidentShow.url(incident.id)}
                                            prefetch
                                            className="font-mono text-xs"
                                        >
                                            INC-{format.number(incident.id)}
                                        </TextLink>
                                    ),
                                },
                                {
                                    id: 'site',
                                    header: t('Site'),
                                    className: 'max-w-48',
                                    cell: (incident) => (
                                        <EntityLink
                                            name={incident.site_name}
                                            className="font-normal"
                                            icon={incidentSiteAvatar(incident)}
                                        />
                                    ),
                                },
                                {
                                    id: 'check',
                                    header: t('Check'),
                                    cell: (incident) =>
                                        t(checkTypes[incident.check].label),
                                },
                                {
                                    id: 'status',
                                    header: t('Status'),
                                    cell: incidentStatus,
                                },
                            ]}
                            card={(incident) => (
                                <span className="flex items-center justify-between gap-2">
                                    <span className="flex min-w-0 items-center gap-2 text-sm">
                                        <EntityLink
                                            name={incident.site_name}
                                            insideLink
                                            className="font-normal"
                                            icon={incidentSiteAvatar(incident)}
                                        />
                                        <span className="shrink-0 font-mono text-xs">
                                            INC-{format.number(incident.id)}
                                        </span>
                                    </span>
                                    {incidentStatus(incident)}
                                </span>
                            )}
                            empty={
                                <ContentState
                                    state="empty"
                                    title="No incidents for this client."
                                    description="Incident activity will appear here when a check fails."
                                />
                            }
                            count={t('preview.latest_incidents', {
                                count: bufferedIncidents.rows.length,
                                formattedCount: format.number(
                                    bufferedIncidents.rows.length,
                                ),
                            })}
                            footerExtra={
                                <TextLink
                                    href={incidentsIndex.url({
                                        query: {
                                            'filter[client_id]': client.id,
                                            'filter[status]': 'all',
                                            'filter[period]': 'all',
                                        },
                                    })}
                                >
                                    {t('preview.all_client_incidents')}
                                </TextLink>
                            }
                        />
                    </div>
                )}
                {tab === 'messages' && (
                    <div className="space-y-3">
                        <h2 className="sr-only">{t('client_messages.tab')}</h2>
                        <ClientMessagesList client={client} />
                    </div>
                )}
            </PageContainer>
        </>
    );
}
