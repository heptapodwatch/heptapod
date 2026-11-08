import { Deferred, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import { AlertChannelsLine } from '@/components/alert-channels-line';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';
import { LighthouseScoreGrid } from '@/components/check-evidence';
import { EvidenceFacts } from '@/components/evidence-facts';
import {
    ContentState,
    EventTimeline,
    MonitoringTime,
    responseTimeSummary,
    Sparkline,
    StatusBadge,
    UptimeBar,
} from '@/components/monitoring';
import { SectionHeading } from '@/components/section-heading';
import { StatTile } from '@/components/stat-tile';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { ListHeader, ListRow, rowEmphasisClass } from '@/components/ui/table';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import { useFormat } from '@/hooks/use-format';
import { bySeverity, checkState, isAttentionState } from '@/lib/status';
import type { SiteState } from '@/types/monitoring';
import { cn } from '@/lib/utils';
import { show as incidentShow } from '@/routes/incidents';
import { index as maintenanceIndex } from '@/routes/maintenance';
import { show as checkShow } from '@/routes/sites/checks';

type Page = Heptapod.Data.Ui.SiteOverviewPageData;
type Check = Heptapod.Data.Ui.CheckStripData;

type SiteCheckRow = {
    key: string;
    state: Heptapod.Enums.SiteStatus;
    /** StatusBadge label when the state word alone would mislead (a suppressed gap). */
    stateLabel?: string;
    what: string;
    detail: string;
    when: ReactNode;
    /** The row's one target; a paused site has none (Resume is the header action). */
    href: string | null;
    checkId?: number;
};

/** The Sites attention band's State · What · When widths. */
const checkListColumns = 'sm:grid-cols-[8.5rem_minmax(0,1fr)_7rem]';

/** An unavailable check cannot run at all, so on its own site it needs attention too. */
function needsAttention(state: SiteState): boolean {
    return isAttentionState(state) || state === 'unavailable';
}

function checkHref(siteId: number, check: Check): string {
    return check.open_incident_id !== null
        ? incidentShow.url(check.open_incident_id)
        : checkShow.url({ site: siteId, check: check.id });
}

/** "Is this site OK, and what needs me?": attention first, then the 30-day record and what is coming, one row per other check, recent events. */
export function SiteOverviewContent({ page }: { page: Page }) {
    const { t } = useTranslation();
    const attentionId = useId();
    const checksId = useId();
    const { site } = page;
    // Enabled checks by severity, turned-off ones last.
    const checks = [...site.checks].sort(
        (a, b) =>
            bySeverity(
                a.enabled ? checkState(a) : 'disabled',
                b.enabled ? checkState(b) : 'disabled',
            ) || a.id - b.id,
    );
    const attentionChecks = checks.filter(
        (check) => check.enabled && needsAttention(checkState(check)),
    );
    const maintenance =
        page.maintenance?.state === 'active' ? page.maintenance : null;
    const siteRows: SiteCheckRow[] = [
        ...(site.state === 'paused'
            ? [
                  {
                      key: 'paused',
                      state: 'paused' as const,
                      what: t('site_overview.paused'),
                      detail: t('site_overview.paused_detail'),
                      when: null,
                      href: null,
                  },
              ]
            : []),
        ...(maintenance
            ? [
                  {
                      key: 'maintenance',
                      state: 'maintenance' as const,
                      what:
                          maintenance.reason ?? t('site_overview.maintenance'),
                      detail: t(
                          maintenance.muteAlerts
                              ? 'Alerts muted'
                              : 'Alerts continue',
                      ),
                      when: maintenance.endsAt && (
                          <span>
                              {t('Until')}{' '}
                              <AbsoluteTime value={maintenance.endsAt} />
                          </span>
                      ),
                      href: maintenanceIndex.url({
                          query: { site_id: site.id },
                      }),
                  },
              ]
            : []),
    ];
    const hasLaunchpad = site.checks.some((check) => check.key === 'uptime');
    // A healthy row of a carded check only repeats its card; a non-OK one keeps its cause.
    const otherChecks = checks.filter(
        (check) =>
            !attentionChecks.includes(check) &&
            !(
                hasLaunchpad &&
                ['uptime', 'performance', 'lighthouse'].includes(check.key) &&
                check.enabled &&
                checkState(check) === 'ok'
            ),
    );
    const uptime = site.checks.find((check) => check.key === 'uptime');
    const lighthouse = site.checks.find(
        (check) => check.key === 'lighthouse' && check.enabled,
    );
    return (
        <div className="space-y-6" data-site-overview>
            {/* As on Sites: nothing needs attention, no section; the header meta already states the site's state. */}
            {siteRows.length + attentionChecks.length > 0 && (
                <section
                    data-site-attention
                    className="space-y-3"
                    aria-labelledby={attentionId}
                >
                    <SectionHeading id={attentionId}>
                        {t('Needs attention')}
                    </SectionHeading>
                    <SiteCheckList
                        siteId={site.id}
                        leading={siteRows}
                        checks={attentionChecks}
                    />
                </section>
            )}
            {/* Each line's height as loaded: the values wrap to two lines below sm and fit on one above. */}
            <Deferred
                data={['record', 'upcoming']}
                fallback={
                    <div aria-busy="true">
                        <EvidenceFacts
                            rows={[
                                ...(uptime
                                    ? [
                                          {
                                              label: t('Last 30 days'),
                                              value: (
                                                  <Skeleton className="h-[2lh] w-96 max-w-full sm:h-[1lh]" />
                                              ),
                                          },
                                      ]
                                    : []),
                                {
                                    label: t('Coming up'),
                                    value: (
                                        <Skeleton className="h-[2lh] w-72 max-w-full sm:h-[1lh]" />
                                    ),
                                },
                            ]}
                        />
                    </div>
                }
            >
                <SiteRecordLines page={page} />
            </Deferred>
            {uptime && (
                // One row from xl only: narrower, a card's title and Open check collide (DE at 1024).
                <div
                    data-site-launchpad
                    className="grid gap-6 xl:auto-cols-fr xl:grid-flow-col"
                >
                    <UptimeCard page={page} uptime={uptime} />
                    <ResponseCard
                        page={page}
                        check={
                            site.checks.find(
                                (check) =>
                                    check.key === 'performance' &&
                                    check.enabled,
                            ) ?? uptime
                        }
                    />
                    {lighthouse && (
                        <LighthouseCard page={page} lighthouse={lighthouse} />
                    )}
                </div>
            )}
            {otherChecks.length > 0 && (
                <section className="space-y-3" aria-labelledby={checksId}>
                    <SectionHeading id={checksId}>{t('Checks')}</SectionHeading>
                    <SiteCheckList siteId={site.id} checks={otherChecks} />
                </section>
            )}
            <Card className="min-w-0">
                <CardHeader>
                    <CardTitle>{t('Recent events')}</CardTitle>
                </CardHeader>
                <CardContent>
                    {page.events.length ? (
                        <EventTimeline events={page.events} />
                    ) : (
                        <ContentState
                            bare
                            state="empty"
                            title="No events yet"
                            description="Events appear here when a state changes, not after every check run."
                        />
                    )}
                </CardContent>
            </Card>
            {uptime?.enabled && (
                <AlertChannelsLine
                    siteId={site.id}
                    checkId={uptime.id}
                    category="outage"
                    count={page.alert_channels}
                    canOpenSettings={page.can_move}
                />
            )}
        </div>
    );
}

/** Last 30 days and Coming up, in words you can read out on a client call. */
function SiteRecordLines({ page }: { page: Page }) {
    const { t } = useTranslation();
    const format = useFormat();
    const { record, upcoming = [] } = page;
    const coming = upcoming.map((item) =>
        item.type === 'maintenance'
            ? t('site_overview.coming_maintenance', {
                  time: format.timestamp(item.date),
              })
            : t(
                  item.type === 'certificate'
                      ? 'site_overview.coming_certificate'
                      : 'site_overview.coming_domain',
                  { date: format.calendarDate(item.date) },
              ),
    );
    return (
        <div data-site-record>
            <EvidenceFacts
                rows={[
                    ...(record
                        ? [
                              {
                                  label: t('Last 30 days'),
                                  value:
                                      record.last_outage_started_at === null
                                          ? t('site_overview.record_no_outage')
                                          : t('site_overview.record_outages', {
                                                count: record.outage_count,
                                                formattedCount: format.number(
                                                    record.outage_count,
                                                ),
                                                duration: format.duration(
                                                    record.downtime_seconds,
                                                ),
                                                date: format.calendarDate(
                                                    record.last_outage_started_at,
                                                ),
                                            }),
                              },
                          ]
                        : []),
                    {
                        label: t('Coming up'),
                        value: coming.length
                            ? coming.join(' · ')
                            : t('site_overview.coming_nothing'),
                    },
                ]}
            />
        </div>
    );
}

/** The row of one check: its cause or current headline, the check name, and since when (open incident) or when last checked. */
function useCheckRow(siteId: number) {
    const { t } = useTranslation();
    const format = useFormat();
    const headline = useCheckHeadline();
    return (check: Check): SiteCheckRow => {
        const state = check.enabled ? checkState(check) : 'disabled';
        return {
            key: `check:${check.id}`,
            checkId: check.id,
            state,
            stateLabel: check.freshness_suppressed_by
                ? 'No current data'
                : undefined,
            // An attention row names its issue (incl. the uptime-dependency headline); an unavailable check's cause is its missing capability.
            what:
                needsAttention(state) && !check.unavailable_reason
                    ? format.issueHeadline({
                          ...check,
                          check: check.key,
                          fallback: headline(check),
                      })
                    : headline(check),
            detail: t(check.label_key),
            when: check.open_incident_started_at ? (
                <MonitoringTime
                    value={check.open_incident_started_at}
                    mode="since"
                    prefix={t('Since')}
                />
            ) : check.checked_at ? (
                <MonitoringTime
                    value={check.checked_at}
                    mode="since"
                    prefix={t('Checked')}
                />
            ) : check.enabled && !check.unavailable_reason ? (
                t('Awaiting first result')
            ) : null,
            href: checkHref(siteId, check),
        };
    };
}

/**
 * Needs attention and Checks: one bordered list with the Sites attention band's State · What · When columns. A row with a
 * target opens it from anywhere; the headline link stays its one tab stop.
 */
export function SiteCheckList({
    siteId,
    checks,
    leading = [],
}: {
    siteId: number;
    checks: Check[];
    /** Site-level rows before the checks: a paused site, an active maintenance window. */
    leading?: SiteCheckRow[];
}) {
    const { t } = useTranslation();
    const checkRow = useCheckRow(siteId);
    return (
        <div className="@container/table overflow-hidden rounded-lg border">
            <ListHeader className={cn(checkListColumns, 'sm:grid')}>
                <span>{t('State')}</span>
                <span>{t('What')}</span>
                <span>{t('When')}</span>
            </ListHeader>
            {[...leading, ...checks.map(checkRow)].map((row) => (
                <ListRow
                    key={row.key}
                    data-site-check={row.checkId}
                    className={cn(
                        checkListColumns,
                        'sm:items-center',
                        row.href && rowTargetClass,
                        rowEmphasisClass(row.state),
                    )}
                    onClick={row.href ? openRowTarget(row.href) : undefined}
                >
                    <StatusBadge state={row.state} label={row.stateLabel} />
                    <div className="min-w-0">
                        <EntityLink
                            href={row.href ?? undefined}
                            name={row.what}
                        />
                        <p className="truncate text-xs text-muted-foreground">
                            {row.detail}
                        </p>
                    </div>
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.when}
                    </span>
                </ListRow>
            ))}
        </div>
    );
}

/** Uptime for 24 hours and for the 30 days its daily buckets show. */
function UptimeCard({ page, uptime }: { page: Page; uptime: Check }) {
    const { t } = useTranslation();
    const format = useFormat();
    const days = page.history.slice(-30);
    const measured = page.history.length === 90;
    const legend = (color: string, label: string) => (
        <span className="inline-flex items-center gap-1.5">
            <span
                className="h-3 w-1.5 rounded-xs"
                style={{ backgroundColor: `var(${color})` }}
                aria-hidden="true"
            />
            {t(label)}
        </span>
    );
    const percentage = (value: number | null) =>
        value === null ? '—' : format.uptimePercentage(value);
    return (
        <LaunchpadCard title={t('Uptime')} siteId={page.site.id} check={uptime}>
            <div className="grid grid-cols-2 gap-4">
                <StatTile
                    label={t('24 hours')}
                    value={percentage(page.uptime_ranges.hours24)}
                />
                <StatTile
                    label={t('30 days')}
                    value={percentage(page.uptime_ranges.days30)}
                />
            </div>
            {measured ? (
                <UptimeBar buckets={days} />
            ) : (
                <p className="text-sm text-muted-foreground">{t('No data')}</p>
            )}
            {/* Below the bar at every width: beside the title it wrapped into a tall block on narrow cards. */}
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--foreground-faint)]">
                {legend('--status-ok', 'No interruption')}
                {legend('--status-warn', 'Short interruptions (1–4 min)')}
                {legend('--status-down', 'Outage (5 min or more)')}
                {days.some((b) => b.status === 'no_data') &&
                    legend('--status-pending', 'No data')}
                {days.some((b) => b.status === 'errored') &&
                    legend('--status-error', 'Check error')}
                {days.some((b) => b.status === 'warning') &&
                    legend('--status-warn', 'Warning')}
            </div>
        </LaunchpadCard>
    );
}

/** The launchpad card anatomy: title, Open check, one or two headline numbers and one small visual; the detail lives on the check tab. */
function LaunchpadCard({
    title,
    siteId,
    check,
    children,
}: {
    title: string;
    siteId: number;
    check: Check;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    return (
        <Card className="min-w-0" data-launchpad-card={check.key}>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardAction>
                    <Button asChild size="sm" variant="ghost">
                        <Link
                            href={checkShow.url({
                                site: siteId,
                                check: check.id,
                            })}
                            prefetch
                        >
                            {t('Open check')}
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-4">{children}</CardContent>
        </Card>
    );
}

/** The Performance tab's latest hourly average and the 24-hour peak sparkline; opens Performance, or Uptime without it. */
function ResponseCard({ page, check }: { page: Page; check: Check }) {
    const { t } = useTranslation();
    const format = useFormat();
    const uptime = page.site.checks.find((item) => item.key === 'uptime');
    return (
        <LaunchpadCard
            title={t('Response time')}
            siteId={page.site.id}
            check={check}
        >
            <StatTile
                label={t('Hourly average')}
                value={
                    <Deferred
                        data="record"
                        fallback={
                            <Skeleton
                                aria-busy="true"
                                className="h-[1.25em] w-24"
                            />
                        }
                    >
                        {page.record?.response_ms == null
                            ? '—'
                            : `${format.milliseconds(page.record.response_ms)} ${t('ms')}`}
                    </Deferred>
                }
            />
            {/* Peaks, not averages: the caption says so, and the summary replaces the decorative line for screen readers. */}
            <div className="space-y-1">
                <Sparkline
                    data={page.site.response}
                    state={uptime ? checkState(uptime) : 'ok'}
                    decorative
                />
                <span className="sr-only">
                    {responseTimeSummary(page.site.response, t, format)}
                </span>
                <p aria-hidden="true" className="text-xs text-muted-foreground">
                    {t('site_overview.response_peaks')}
                </p>
            </div>
        </LaunchpadCard>
    );
}

/** The Lighthouse tab's last successful scores with its own score rings and thresholds; no history chart. */
function LighthouseCard({
    page,
    lighthouse,
}: {
    page: Page;
    lighthouse: Check;
}) {
    const { t } = useTranslation();
    const history =
        page.lighthouse?.check_type === 'lighthouse' ? page.lighthouse : null;
    const metrics = history?.last_successful_metrics ?? null;
    return (
        <LaunchpadCard
            title={t(lighthouse.label_key)}
            siteId={page.site.id}
            check={lighthouse}
        >
            <Deferred
                data="lighthouse"
                // The loaded layout with skeleton rings and time: the real labels wrap the same, so nothing below jumps.
                fallback={
                    <>
                        <LighthouseScoreGrid
                            metrics={null}
                            className="grid-cols-4 gap-2"
                        />
                        <Skeleton aria-busy="true" className="h-4 w-28" />
                    </>
                }
            >
                {metrics ? (
                    <LighthouseScoreGrid
                        metrics={metrics}
                        className="grid-cols-4 gap-2"
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('No evidence retained for this check.')}
                    </p>
                )}
                {history?.last_successful_at && (
                    <p className="text-xs text-muted-foreground">
                        <MonitoringTime
                            value={history.last_successful_at}
                            mode="since"
                            prefix={t('Checked')}
                            className="font-mono"
                        />
                    </p>
                )}
            </Deferred>
        </LaunchpadCard>
    );
}
