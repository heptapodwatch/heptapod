import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CheckCard, MonitoringTime, UptimeBar } from '@/components/monitoring';
import { useFormat } from '@/hooks/use-format';
import { bySeverity } from '@/lib/status';
import { show as incidentShow } from '@/routes/incidents';
import { show as checkShow } from '@/routes/sites/checks';

export function SiteOverviewContent({
    page,
}: {
    page: Heptapod.Data.Ui.SiteOverviewPageData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const checks = [...page.site.checks].sort(
        (a, b) =>
            bySeverity(
                a.enabled ? a.state : 'disabled',
                b.enabled ? b.state : 'disabled',
            ) || a.id - b.id,
    );
    const lastOutage = page.incidents
        .filter(
            (incident) =>
                incident.check === 'uptime' && incident.cause === 'failed',
        )
        .sort((a, b) => Date.parse(b.started_at) - Date.parse(a.started_at))[0];
    const lastDay = page.history.findLast(
        (bucket) => bucket.status === 'failed',
    );
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
    return (
        <div className="space-y-6" data-site-overview>
            {page.site.checks.some((check) => check.key === 'uptime') && (
                <Card className="overflow-hidden">
                    <CardHeader>
                        <CardTitle>
                            <span className="sm:hidden">
                                {t('uptime.history_title', { count: 45 })}
                            </span>
                            <span className="hidden sm:inline">
                                {t('uptime.history_title', { count: 90 })}
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {page.history.length === 90 ? (
                            <UptimeBar buckets={page.history} mobileDays={45} />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('No data')}
                            </p>
                        )}
                        {/* Below the bar at every width: beside the title it wrapped into a tall block on narrow cards. */}
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--foreground-faint)]">
                            {legend('--status-ok', 'No interruption')}
                            {legend(
                                '--status-warn',
                                'Short interruptions (1–4 min)',
                            )}
                            {legend('--status-down', 'Outage (5 min or more)')}
                            {page.history.some((b) => b.status === 'no_data') &&
                                legend('--status-pending', 'No data')}
                            {page.history.some((b) => b.status === 'errored') &&
                                legend('--status-error', 'Check error')}
                            {page.history.some((b) => b.status === 'warning') &&
                                legend('--status-warn', 'Warning')}
                        </div>
                        <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                            <span className="font-mono">
                                {page.uptime_ranges.days90 === null
                                    ? t('No measured uptime')
                                    : format.uptimePercentage(
                                          page.uptime_ranges.days90,
                                          1,
                                      )}
                            </span>
                            {lastOutage ? (
                                <span>
                                    {t('Last outage')}:{' '}
                                    <MonitoringTime
                                        value={lastOutage.started_at}
                                        mode="since"
                                    />{' '}
                                    ·{' '}
                                    <span className="font-mono">
                                        {format.duration(
                                            lastOutage.duration_seconds,
                                        )}
                                    </span>
                                </span>
                            ) : lastDay ? (
                                <span>
                                    {t('Latest outage day in daily history')}:{' '}
                                    {format.date(lastDay.date, {
                                        month: 'short',
                                        day: 'numeric',
                                    })}{' '}
                                    · {t('Estimated downtime')}{' '}
                                    <span className="font-mono">
                                        {format.duration(
                                            lastDay.failed_seconds,
                                        )}
                                    </span>
                                </span>
                            ) : (
                                <span>{t('No outage in daily history')}</span>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {checks.map((check) => (
                    <CheckCard
                        key={check.id}
                        check={check}
                        href={
                            check.open_incident_id !== null
                                ? incidentShow.url(check.open_incident_id)
                                : checkShow.url({
                                      site: page.site.id,
                                      check: check.id,
                                  })
                        }
                    />
                ))}
            </div>
        </div>
    );
}
