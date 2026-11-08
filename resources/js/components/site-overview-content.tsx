import { Deferred, Link } from '@inertiajs/react';
import { ArrowRight, ChevronRight } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import { AlertChannelsLine } from '@/components/alert-channels-line';
import { openRowTarget, rowTargetClass } from '@/components/entity-link';
import { EvidenceFacts } from '@/components/evidence-facts';
import {
    ContentState,
    EventTimeline,
    MonitoringTime,
    StatusBadge,
    StatusDot,
    UptimeBar,
} from '@/components/monitoring';
import { SectionHeading } from '@/components/section-heading';
import { StatTile } from '@/components/stat-tile';
import { TextLink } from '@/components/text-link';
import { TruncatedText } from '@/components/truncated-text';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { ListHeader, ListRow } from '@/components/ui/table';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import { useFormat } from '@/hooks/use-format';
import { focusRingClass } from '@/lib/interaction';
import { bySeverity, checkState, isAttentionState } from '@/lib/status';
import type { SiteState } from '@/types/monitoring';
import { cn } from '@/lib/utils';
import { show as incidentShow } from '@/routes/incidents';
import { index as maintenanceIndex } from '@/routes/maintenance';
import { show as checkShow } from '@/routes/sites/checks';

type Page = Heptapod.Data.Ui.SiteOverviewPageData;
type Check = Heptapod.Data.Ui.CheckStripData;

type AttentionItem = {
    key: string;
    state: Heptapod.Enums.SiteStatus;
    what: string;
    detail: string;
    when: ReactNode;
    link: { href: string; label: string } | null;
};

const attentionColumns = 'sm:grid-cols-[8.5rem_minmax(0,1fr)_11rem_8rem]';

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
    const format = useFormat();
    const headline = useCheckHeadline();
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
    const items: AttentionItem[] = [
        ...(site.state === 'paused'
            ? [
                  {
                      key: 'paused',
                      state: 'paused' as const,
                      what: t('site_overview.paused'),
                      detail: t('site_overview.paused_detail'),
                      when: null,
                      link: null,
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
                      link: {
                          href: maintenanceIndex.url({
                              query: { site_id: site.id },
                          }),
                          label: t('Open maintenance'),
                      },
                  },
              ]
            : []),
        ...attentionChecks.map((check) => ({
            key: `check:${check.id}`,
            state: checkState(check),
            // An unavailable check's cause is its missing capability, not its last retained result.
            what: check.unavailable_reason
                ? headline(check)
                : format.issueHeadline({
                      ...check,
                      check: check.key,
                      fallback: headline(check),
                  }),
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
            ) : check.unavailable_reason ? null : (
                t('Awaiting first result')
            ),
            link: {
                href: checkHref(site.id, check),
                label: t(
                    check.open_incident_id !== null
                        ? 'Open incident'
                        : 'Open check',
                ),
            },
        })),
    ];
    const otherChecks = checks.filter(
        (check) => !attentionChecks.includes(check),
    );
    const enabledChecks = site.checks.filter((check) => check.enabled);
    const uptime = site.checks.find((check) => check.key === 'uptime');
    return (
        <div className="space-y-6" data-site-overview>
            <section className="space-y-3" aria-labelledby={attentionId}>
                <SectionHeading id={attentionId}>
                    {t('Needs attention')}
                </SectionHeading>
                {items.length === 0 ? (
                    <p
                        data-site-calm
                        className="flex items-center gap-3 rounded-lg border px-4 py-3 text-sm"
                    >
                        <span aria-hidden className="inline-flex">
                            <StatusDot state="ok" />
                        </span>
                        <span>
                            {t('site_overview.nothing_needs_attention')}
                            {enabledChecks.length > 0 &&
                                enabledChecks.every(
                                    (check) => checkState(check) === 'ok',
                                ) && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        {t('site_overview.all_checks_ok', {
                                            count: enabledChecks.length,
                                            formattedCount: format.number(
                                                enabledChecks.length,
                                            ),
                                        })}
                                    </span>
                                )}
                        </span>
                    </p>
                ) : (
                    <div
                        data-site-attention
                        className="@container/table overflow-hidden rounded-lg border"
                    >
                        <ListHeader className={cn(attentionColumns, 'sm:grid')}>
                            <span>{t('State')}</span>
                            <span>{t('What')}</span>
                            <span>{t('When')}</span>
                            <span />
                        </ListHeader>
                        {items.map((item) => (
                            <ListRow
                                key={item.key}
                                className={cn(
                                    attentionColumns,
                                    'sm:items-center',
                                )}
                            >
                                <StatusBadge state={item.state} />
                                <div className="min-w-0">
                                    <TruncatedText content={item.what}>
                                        <p className="truncate font-medium">
                                            {item.what}
                                        </p>
                                    </TruncatedText>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {item.detail}
                                    </p>
                                </div>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {item.when}
                                </span>
                                {item.link && (
                                    <TextLink
                                        className="text-xs"
                                        href={item.link.href}
                                    >
                                        {item.link.label}
                                    </TextLink>
                                )}
                            </ListRow>
                        ))}
                    </div>
                )}
            </section>
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
                                                  <Skeleton className="h-4 w-96 max-w-full" />
                                              ),
                                          },
                                      ]
                                    : []),
                                {
                                    label: t('Coming up'),
                                    value: (
                                        <Skeleton className="h-4 w-72 max-w-full" />
                                    ),
                                },
                            ]}
                        />
                    </div>
                }
            >
                <SiteRecordLines page={page} />
            </Deferred>
            {otherChecks.length > 0 && (
                <section className="space-y-3" aria-labelledby={checksId}>
                    <SectionHeading id={checksId}>
                        {t(items.length ? 'Other checks' : 'Checks')}
                    </SectionHeading>
                    <SiteCheckRows siteId={site.id} checks={otherChecks} />
                </section>
            )}
            {/* Side by side only from xl: narrower, the Uptime card's title and Open check collide (DE at 1024). */}
            <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
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
                {uptime && <UptimeCard page={page} uptime={uptime} />}
            </div>
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
    const days30 = page.uptime_ranges.days30;
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
                                  value: [
                                      days30 === null
                                          ? t('No measured uptime')
                                          : t('site_overview.record_uptime', {
                                                value: format.uptimePercentage(
                                                    days30,
                                                ),
                                            }),
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
                                      ...(record.response_ms === null
                                          ? []
                                          : [
                                                t(
                                                    'site_overview.record_response',
                                                    {
                                                        value: format.milliseconds(
                                                            record.response_ms,
                                                        ),
                                                    },
                                                ),
                                            ]),
                                  ].join(' · '),
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

/** One compact row per check: state and name, the current headline, last checked; the row opens the check or its incident. */
export function SiteCheckRows({
    siteId,
    checks,
}: {
    siteId: number;
    checks: Check[];
}) {
    const { t } = useTranslation();
    const headlineFor = useCheckHeadline();
    return (
        <div className="@container/table overflow-hidden rounded-lg border">
            {checks.map((check) => {
                const href = checkHref(siteId, check);
                const headline = headlineFor(check);
                return (
                    <ListRow
                        key={check.id}
                        data-site-check={check.id}
                        className={cn(
                            'grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 sm:grid-cols-[12rem_minmax(0,1fr)_6rem_1rem]',
                            rowTargetClass,
                        )}
                        onClick={openRowTarget(href)}
                    >
                        <Link
                            href={href}
                            prefetch
                            className={cn(
                                'flex min-w-0 items-center gap-2 rounded-sm font-medium',
                                focusRingClass,
                            )}
                        >
                            <StatusDot
                                state={
                                    check.enabled
                                        ? checkState(check)
                                        : 'disabled'
                                }
                                label={
                                    check.freshness_suppressed_by
                                        ? 'No current data'
                                        : undefined
                                }
                            />
                            <span className="truncate">
                                {t(check.label_key)}
                            </span>
                        </Link>
                        <span className="text-right font-mono text-xs text-muted-foreground sm:order-3">
                            {!check.enabled
                                ? t('Off')
                                : check.checked_at && (
                                      <MonitoringTime
                                          value={check.checked_at}
                                      />
                                  )}
                        </span>
                        <TruncatedText content={headline}>
                            <span className="col-span-2 truncate text-xs text-muted-foreground sm:order-2 sm:col-span-1 sm:text-cell">
                                {headline}
                            </span>
                        </TruncatedText>
                        <ChevronRight
                            aria-hidden
                            className="hidden size-4 text-muted-foreground sm:order-4 sm:block"
                        />
                    </ListRow>
                );
            })}
        </div>
    );
}

/** Uptime beside the events: 24 hours and 90 days, the last 30 days as daily buckets. */
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
        <Card className="min-w-0">
            <CardHeader>
                <CardTitle>{t('Uptime')}</CardTitle>
                <CardAction>
                    <Button asChild size="sm" variant="ghost">
                        <Link
                            href={checkShow.url({
                                site: page.site.id,
                                check: uptime.id,
                            })}
                            prefetch
                        >
                            {t('Open check')}
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <StatTile
                        label={t('24 hours')}
                        value={percentage(page.uptime_ranges.hours24)}
                    />
                    <StatTile
                        label={t('90 days')}
                        value={percentage(page.uptime_ranges.days90)}
                    />
                </div>
                {measured ? (
                    <UptimeBar buckets={days} />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('No data')}
                    </p>
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
            </CardContent>
        </Card>
    );
}
