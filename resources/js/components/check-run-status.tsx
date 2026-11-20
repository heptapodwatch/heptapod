import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    RunLine,
    runLifecycle,
    runProgress,
    useRunDetail,
} from '@/components/run-family';
import { useFormat } from '@/hooks/use-format';
import { useRunningNow } from '@/hooks/use-running-now';
import { currentRunState, useRunState } from '@/lib/live-store';

/**
 * The run of one check in flight (any origin: a scheduled run disables Run check too), with live progress merged in.
 * `mine` comes from Running now, which knows who started the manual run.
 */
export function useCheckRun(
    checkId: number,
    persisted: Heptapod.Data.Ui.CheckRunStateData | null | undefined,
) {
    const state = currentRunState(useRunState(checkId), persisted);
    const { runs } = useRunningNow();
    if (!state || state.phase === 'finished') return null;
    return {
        state,
        lifecycle: runLifecycle(state.phase),
        progress: runProgress(state),
        mine: runs.some(
            (run) =>
                run.check_id === checkId &&
                run.mine &&
                run.lifecycle !== 'finished',
        ),
    };
}

/** Why Run check is disabled, directly below it: "Running since 14:02 · 3,214 of 5,000 URLs", "Queued · #2". */
export function RunCheckReason({
    run,
}: {
    run: NonNullable<ReturnType<typeof useCheckRun>>;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const detail = useRunDetail();
    const running = run.lifecycle === 'running';
    return (
        <span role="status" className="block md:flex md:justify-end">
            <RunLine
                phase={run.lifecycle}
                progress={run.progress}
                label={
                    running && run.state.started_at
                        ? t('run.runningSince', {
                              time: format.time(run.state.started_at),
                          })
                        : t(running ? 'Running' : 'Queued')
                }
                detail={
                    running && !run.progress
                        ? undefined
                        : detail(run.state, { narrow: false })
                }
                mine={run.mine}
                className="px-0 py-0.5"
            />
        </span>
    );
}

/** The check tab's and the incident timeline's status row: the run with its bar, and Cancel where allowed. */
export function CheckRunStatusRow({
    run,
    action,
}: {
    run: NonNullable<ReturnType<typeof useCheckRun>>;
    action?: ReactNode;
}) {
    const { t } = useTranslation();
    const detail = useRunDetail();
    return (
        <div role="status" className="rounded-[10px] border bg-card px-1 py-1">
            <RunLine
                phase={run.lifecycle}
                progress={run.progress}
                label={t(run.lifecycle === 'running' ? 'Running' : 'Queued')}
                detail={detail(run.state)}
                mine={run.mine}
                bar
                trailing={action}
            />
        </div>
    );
}
