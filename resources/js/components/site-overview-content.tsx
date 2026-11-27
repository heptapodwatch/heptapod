import { Deferred, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertChannelsLine } from '@/components/alert-channels-line';
import { LighthouseScoreGrid } from '@/components/check-evidence';
import { EvidenceFacts } from '@/components/evidence-facts';
import {
    MonitoringTime,
    responseLineColor,
    responseTimeSummary,
    UptimeBar,
} from '@/components/monitoring';
import { SiteServerCard } from '@/components/server-manage';
import { Sparkline } from '@/components/sparkline';
import { StatTile } from '@/components/stat-tile';
import { TextLink } from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/hooks/use-format';
import { checkState } from '@/lib/status';
import { show as checkShow } from '@/routes/sites/checks';

type Page = Heptapod.Data.Ui.SiteOverviewPageData;
type Check = Heptapod.Data.Ui.CheckStripData;

/**
 * "Is this site OK?" below the Overview header: the uptime, response time and Lighthouse cards, the alert line and the
 * site's repository links. The rail beside it lists every check with its state, so nothing here repeats it.
 */
export function SiteOverviewContent({ page }: { page: Page }) {
    const { t } = useTranslation();
    const uptime = page.site.checks.find((check) => check.key === 'uptime');
    return (
        <>
            {uptime && <SiteLaunchpad page={page} uptime={uptime} />}
            {/* One row of cards on every site: Alerts, and Server where the site runs on a linked one. */}
            <div className="@container/routing">
                <div className="grid gap-4 @[44rem]/routing:grid-cols-2">
                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>
                                {t('alert_channels.card_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {/* The former alert line, unchanged: Details only for those who may change the routing. */}
                            {uptime?.enabled ? (
                                <AlertChannelsLine
                                    siteId={page.site.id}
                                    checkId={uptime.id}
                                    category="outage"
                                    count={page.alert_channels}
                                    canOpenSettings={page.can_move}
                                />
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    {t('alert_channels.no_uptime')}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    {page.server && <SiteServerCard server={page.server} />}
                </div>
            </div>
            <SiteRepositories repositories={page.repositories} />
        </>
    );
}

/** The site's repository links, read-only for everyone who sees the site (edited in Site settings); none without links. */
function SiteRepositories({
    repositories,
}: {
    repositories: Heptapod.Data.Ui.SiteRepositoryData[];
}) {
    const { t } = useTranslation();
    if (repositories.length === 0) return null;
    return (
        <EvidenceFacts
            rows={[
                {
                    label: t('repositories.title'),
                    value: (
                        <ul className="flex flex-wrap gap-x-4 gap-y-1">
                            {repositories.map((repository) => (
                                <li key={repository.url} className="min-w-0">
                                    {repository.label && (
                                        <span className="mr-1.5 text-muted-foreground">
                                            {repository.label}
                                        </span>
                                    )}
                                    <TextLink
                                        external
                                        url
                                        href={repository.url}
                                    >
                                        {repository.url.replace(
                                            /^https:\/\//,
                                            '',
                                        )}
                                    </TextLink>
                                </li>
                            ))}
                        </ul>
                    ),
                },
            ]}
        />
    );
}

/** Uptime and Response time side by side, Lighthouse full width below: sized by the right column, not the viewport. */
function SiteLaunchpad({ page, uptime }: { page: Page; uptime: Check }) {
    const { site } = page;
    const lighthouseCheck = site.checks.find(
        (check) => check.key === 'lighthouse' && check.enabled,
    );
    return (
        <div className="@container/launchpad">
            <div
                data-site-launchpad
                className="grid gap-4 @[44rem]/launchpad:grid-cols-2"
            >
                <UptimeCard page={page} uptime={uptime} />
                <ResponseCard
                    page={page}
                    check={
                        site.checks.find(
                            (check) =>
                                check.key === 'performance' && check.enabled,
                        ) ?? uptime
                    }
                />
                {lighthouseCheck && (
                    <div className="@[44rem]/launchpad:col-span-2">
                        <LighthouseCard
                            page={page}
                            lighthouse={lighthouseCheck}
                        />
                    </div>
                )}
            </div>
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
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-foreground-faint">
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
                    <Button asChild variant="ghost">
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
                        data="response_ms"
                        fallback={
                            <Skeleton
                                aria-busy="true"
                                className="h-[1.25em] w-24"
                            />
                        }
                    >
                        {page.response_ms == null
                            ? '—'
                            : `${format.milliseconds(page.response_ms)} ${t('ms')}`}
                    </Deferred>
                }
            />
            {/* Peaks, not averages: the caption says so, and the summary replaces the decorative line for screen readers. */}
            <div className="space-y-1">
                <Sparkline
                    data={page.site.response}
                    state={uptime ? checkState(uptime) : 'ok'}
                    decorative
                    color={responseLineColor(page.site.checks)}
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
                            className="grid-cols-2 gap-4 sm:grid-cols-4"
                        />
                        <Skeleton aria-busy="true" className="h-4 w-28" />
                    </>
                }
            >
                {metrics ? (
                    <LighthouseScoreGrid
                        metrics={metrics}
                        className="grid-cols-2 gap-4 sm:grid-cols-4"
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
