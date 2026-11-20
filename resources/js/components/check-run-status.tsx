import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    RunLine,
    runLifecycle,
    runProgress,
    useRunDetail,
} from '@/components/run-family';
import { usePage } from '@inertiajs/react';
import { useFormat } from '@/hooks/use-format';
import { currentRunState, useRunState, type RunState } from '@/lib/live-store';

export type CheckRun = {
    state: RunState;
    lifecycle: ReturnType<typeof runLifecycle>;
    progress: ReturnType<typeof runProgress>;
    manual: boolean;
    mine: boolean;
};

/**
 * The run in flight of one check from the page's own data and the live store (any origin: a scheduled run disables Run
 * check too). Who started it comes from the attempt itself; the shared Running now rows only cover a run that started
 * after the page loaded.
 */
export function useCheckRunOf() {
    const { auth, runningNow } = usePage().props;
    return (
        live: RunState | undefined,
        persisted: Heptapod.Data.Ui.CheckRunStateData | null | undefined,
    ): CheckRun | null => {
        const state = currentRunState(live, persisted);
        if (!state || state.phase === 'finished') return null;
        const mine =
            persisted?.attempt_id != null &&
            persisted.attempt_id === state.attempt_id
                ? persisted.actor_id === auth.user?.id
                : (runningNow?.check_runs ?? []).some(
                      (run) =>
                          run.run.attempt_id === state.attempt_id && run.mine,
                  );
        return {
            state,
            lifecycle: runLifecycle(state.phase),
            progress: runProgress(state),
            manual: state.origin === 'manual',
            mine,
        };
    };
}

export function useCheckRun(
    checkId: number,
    persisted: Heptapod.Data.Ui.CheckRunStateData | null | undefined,
): CheckRun | null {
    return useCheckRunOf()(useRunState(checkId), persisted);
}

/** Why Run check is disabled, directly below it: "Running since 14:02 · 3,214 of 5,000 URLs", "Queued · #2". */
export function RunCheckReason({ run }: { run: CheckRun }) {
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
                wrap
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
    run: CheckRun;
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
