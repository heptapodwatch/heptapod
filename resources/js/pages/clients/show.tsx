import { ListFooter } from '@/components/list-footer';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';
import { TextLink } from '@/components/text-link';
import { cn } from '@/lib/utils';
import { SiteAvatar } from '@/components/site-avatar';
import { PageHeader } from '@/components/page-header';
import { StatTile } from '@/components/stat-tile';
import { PageContainer } from '@/components/page-container';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { isCoalescedStateChange, useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { PendingChanges } from '@/components/pending-changes';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/data-table';
import { ClientHeader } from '@/components/client-header';
import { ContentState, StatusBadge } from '@/components/monitoring';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ClientMessagesList } from '@/components/client-messages-list';
import {
    listCardPadding,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { checkTypes } from '@/lib/check-types';
import { incidentCauseState } from '@/lib/status';
import { useFormat } from '@/hooks/use-format';
import { create as siteCreate, show as siteShow } from '@/routes/sites';
import { show as incidentShow } from '@/routes/incidents';

export default function ClientDetail({
    client,
    summary,
    sites,
    pagination,
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
        ['client', 'summary', 'sites', 'pagination', 'incidents'],
        (signal) =>
            signal.ids.client === client.id || isCoalescedStateChange(signal),
    );
    const bufferedSites = useBufferedChanges(sites, (site) => site.id);
    const bufferedIncidents = useBufferedChanges(
        incidents,
        (incident) => incident.id,
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
                        {bufferedSites.rows.length ? (
                            <DataTable
                                data={bufferedSites.rows}
                                clients={[client]}
                                siteHref={(site) => siteShow.url(site.id)}
                                showClient={false}
                                full
                                serverMode
                                onSelect={(site) =>
                                    router.visit(siteShow.url(site.id))
                                }
                                onPrefetch={(site) =>
                                    router.prefetch(siteShow.url(site.id), {
                                        method: 'get',
                                    })
                                }
                            />
                        ) : (
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
                        )}
                        {pagination.total > 0 && (
                            <ListFooter
                                count={t('{{count}} sites', {
                                    count: pagination.total,
                                    formattedCount: format.number(
                                        pagination.total,
                                    ),
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
                    </div>
                )}
                {tab === 'incidents' && (
                    <div className="space-y-3">
                        <h2 className="sr-only">{t('Incidents')}</h2>
                        <PendingChanges
                            count={bufferedIncidents.pendingCount}
                            apply={bufferedIncidents.apply}
                        />
                        {bufferedIncidents.rows.length ? (
                            <div className="overflow-hidden rounded-lg border">
                                <div className="hidden sm:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t('Incident detail')}
                                                </TableHead>
                                                <TableHead>
                                                    {t('Site')}
                                                </TableHead>
                                                <TableHead>
                                                    {t('Check')}
                                                </TableHead>
                                                <TableHead>
                                                    {t('Status')}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {bufferedIncidents.rows.map(
                                                (incident) => (
                                                    <TableRow
                                                        key={incident.id}
                                                        className={
                                                            rowTargetClass
                                                        }
                                                        onClick={openRowTarget(
                                                            incidentShow.url(
                                                                incident.id,
                                                            ),
                                                        )}
                                                    >
                                                        <TableCell>
                                                            <TextLink
                                                                href={incidentShow.url(
                                                                    incident.id,
                                                                )}
                                                                prefetch
                                                                className="font-mono text-xs"
                                                            >
                                                                INC-
                                                                {format.number(
                                                                    incident.id,
                                                                )}
                                                            </TextLink>
                                                        </TableCell>
                                                        <TableCell className="max-w-48">
                                                            <EntityLink
                                                                name={
                                                                    incident.site_name
                                                                }
                                                                className="font-normal"
                                                                icon={
                                                                    <SiteAvatar
                                                                        name={
                                                                            incident.site_name
                                                                        }
                                                                        iconUrl={
                                                                            incident.icon_url
                                                                        }
                                                                        iconBacking={
                                                                            incident.icon_backing
                                                                        }
                                                                    />
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            {t(
                                                                checkTypes[
                                                                    incident
                                                                        .check
                                                                ].label,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {incident.resolved_at ? (
                                                                <StatusBadge
                                                                    state="ok"
                                                                    label="Resolved"
                                                                />
                                                            ) : (
                                                                <StatusBadge
                                                                    state={incidentCauseState(
                                                                        incident,
                                                                    )}
                                                                />
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                                <ul className="divide-y sm:hidden">
                                    {bufferedIncidents.rows.map((incident) => (
                                        <li key={incident.id}>
                                            <Link
                                                href={incidentShow.url(
                                                    incident.id,
                                                )}
                                                prefetch
                                                className={cn(
                                                    'flex items-center justify-between gap-2',
                                                    listCardPadding,
                                                    rowTargetClass,
                                                )}
                                            >
                                                <span className="flex min-w-0 items-center gap-2 text-sm">
                                                    <EntityLink
                                                        name={
                                                            incident.site_name
                                                        }
                                                        insideLink
                                                        className="font-normal"
                                                        icon={
                                                            <SiteAvatar
                                                                name={
                                                                    incident.site_name
                                                                }
                                                                iconUrl={
                                                                    incident.icon_url
                                                                }
                                                                iconBacking={
                                                                    incident.icon_backing
                                                                }
                                                            />
                                                        }
                                                    />
                                                    <span className="shrink-0 font-mono text-xs">
                                                        INC-
                                                        {format.number(
                                                            incident.id,
                                                        )}
                                                    </span>
                                                </span>
                                                {incident.resolved_at ? (
                                                    <StatusBadge
                                                        state="ok"
                                                        label="Resolved"
                                                    />
                                                ) : (
                                                    <StatusBadge
                                                        state={incidentCauseState(
                                                            incident,
                                                        )}
                                                    />
                                                )}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : (
                            <ContentState
                                state="empty"
                                title="No incidents for this client."
                                description="Incident activity will appear here when a check fails."
                            />
                        )}
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
