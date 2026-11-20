import { useTranslation } from 'react-i18next';
import { RunLine, useRunDetail } from '@/components/run-family';
import { useCheckRun, useCheckRunOf } from '@/components/check-run-status';
import { useStreamState } from '@/lib/live-store';
import { show as checkShow } from '@/routes/sites/checks';

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
    // Site-scoped: the page's own checks and the live store, never the capped shared rows.
    const live = useStreamState('runs');
    const runOf = useCheckRunOf();
    const runs = site.checks.flatMap((check) => {
        const run = runOf(live.get(check.id), check.run);
        return run?.manual ? [{ check, run }] : [];
    });
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
                    {runs.map(({ check, run }) => (
                        <RunLine
                            key={check.id}
                            phase={run.lifecycle}
                            progress={run.progress}
                            label={t(check.label_key)}
                            detail={detail(run.state)}
                            mine={run.mine}
                            bar
                            href={checkShow.url({
                                site: site.identity.id,
                                check: check.id,
                            })}
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
export function CheckRowRunMarker({
    check,
}: {
    check: Heptapod.Data.Ui.CheckStripData;
}) {
    const { t } = useTranslation();
    const detail = useRunDetail();
    const run = useCheckRun(check.id, check.run);
    if (!run?.manual) return null;
    return (
        <RunLine
            phase={run.lifecycle}
            progress={run.progress}
            label={t(run.lifecycle === 'running' ? 'Running' : 'Queued')}
            detail={detail(run.state)}
            mine={run.mine}
            className="px-0 py-0.5"
        />
    );
}
