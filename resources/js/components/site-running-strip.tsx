import { useTranslation } from 'react-i18next';
import { RunLine, runProgress, useRunDetail } from '@/components/run-family';
import { useRunningNow } from '@/hooks/use-running-now';
import { checkTypes } from '@/lib/check-types';

type RunAllResult = Heptapod.Data.Ui.SiteOverviewPageData['run_all_result'];

const skipReason: Record<string, string> = {
    fast_only: 'Skipped: fast checks only',
    unavailable: 'Unavailable',
    busy: 'Not ready to run',
    rate_limited: 'Requested recently',
};

/**
 * "Running on this site": the manual runs of this site in flight, one RunLine each (UI rules, Runs). The Run all checks
 * result folds in: the queued checks are these runs, and the checks it did not start follow in one line.
 */
export function SiteRunningStrip({
    site,
    runAllResult,
}: {
    site: Heptapod.Data.Ui.SiteRowData;
    runAllResult: RunAllResult;
}) {
    const { t } = useTranslation();
    const detail = useRunDetail();
    const runs = useRunningNow().runs.filter(
        (run) =>
            run.site_id === site.identity.id && run.lifecycle !== 'finished',
    );
    const notStarted = (runAllResult?.results ?? []).filter(
        (item) => item.status === 'skipped',
    );
    if (runs.length === 0 && notStarted.length === 0) return null;
    return (
        <section
            role="status"
            aria-label={t('run.onThisSite')}
            className="space-y-1 rounded-[10px] border bg-card px-2 py-2"
        >
            <p className="px-2 text-xs text-muted-foreground">
                {t('run.onThisSite')}
            </p>
            {runs.length > 0 && (
                <div className="grid gap-x-6 md:grid-cols-2">
                    {runs.map((run) => (
                        <RunLine
                            key={run.attemptId}
                            phase={run.lifecycle}
                            progress={runProgress(run.state)}
                            label={t(checkTypes[run.check_type].label)}
                            detail={detail(run.state)}
                            mine={run.mine}
                            bar
                            href={run.href}
                        />
                    ))}
                </div>
            )}
            {notStarted.length > 0 && (
                <p className="px-2 text-xs text-muted-foreground">
                    {t('run.notStarted', {
                        checks: notStarted
                            .map((item) => {
                                const check = site.checks.find(
                                    (row) => row.id === item.check_id,
                                );
                                if (!check) {
                                    throw new Error(
                                        `Run-all result references missing check ${item.check_id}`,
                                    );
                                }
                                const reason = skipReason[item.reason ?? ''];
                                if (!reason) {
                                    throw new Error(
                                        `Unknown run-all skip reason: ${item.reason}`,
                                    );
                                }
                                return `${t(check.label_key)} (${t(reason)})`;
                            })
                            .join(', '),
                    })}
                </p>
            )}
        </section>
    );
}

/** The overview row's marker for a manual run of its check in flight: the state word, one detail, You. */
export function CheckRowRunMarker({ checkId }: { checkId: number }) {
    const { t } = useTranslation();
    const detail = useRunDetail();
    const run = useRunningNow().runs.find(
        (item) => item.check_id === checkId && item.lifecycle !== 'finished',
    );
    if (!run) return null;
    return (
        <RunLine
            phase={run.lifecycle}
            progress={runProgress(run.state)}
            label={t(run.lifecycle === 'running' ? 'Running' : 'Queued')}
            detail={detail(run.state)}
            mine={run.mine}
            className="px-0 py-0.5"
        />
    );
}
