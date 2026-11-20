import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import {
    runLifecycle,
    runOutcome,
    type RunLifecycle,
    type RunOutcome,
} from '@/components/run-family';
import {
    currentRunState,
    useStreamState,
    type RunState,
} from '@/lib/live-store';
import { show as checkShow } from '@/routes/sites/checks';

/** A manual check run of the shared Running now data, with its live phase and progress merged in. */
export type RunningCheckRun = Heptapod.Data.Ui.RunningCheckRunData & {
    attemptId: number;
    lifecycle: RunLifecycle;
    state: RunState;
    outcome: RunOutcome | undefined;
    href: string;
};

const openedKey = 'heptapod.running-now.opened';

/** Own finished runs whose check this tab has opened; they leave the shell (UI rules, Runs). */
function openedRuns(): Set<number> {
    return new Set(
        JSON.parse(sessionStorage.getItem(openedKey) ?? '[]') as number[],
    );
}

function markOpened(attemptId: number) {
    const opened = openedRuns();
    if (opened.has(attemptId)) return;
    opened.add(attemptId);
    sessionStorage.setItem(openedKey, JSON.stringify([...opened]));
}

/** The shared `runningNow` prop as the shell and the pages read it: own runs first, live progress merged in. */
export function useRunningNow(): {
    runs: RunningCheckRun[];
    activeCount: number;
} {
    const { props, url } = usePage();
    const data = props.runningNow;
    const live = useStreamState('runs');
    const [now, setNow] = useState(Date.now);
    const path = new URL(url, window.location.origin).pathname;

    // An own finished run whose check page is open counts as opened.
    useEffect(() => {
        data?.check_runs.forEach((run) => {
            if (
                run.result !== null &&
                run.run.attempt_id !== null &&
                checkShow.url({ site: run.site_id, check: run.check_id }) ===
                    path
            ) {
                markOpened(run.run.attempt_id);
            }
        });
    }, [data, path]);

    const runs = useMemo(() => {
        const opened = openedRuns();
        return (data?.check_runs ?? []).flatMap((run): RunningCheckRun[] => {
            const attemptId = run.run.attempt_id;
            if (attemptId === null) {
                throw new Error('A running-now run must name its attempt.');
            }
            const href = checkShow.url({
                site: run.site_id,
                check: run.check_id,
            });
            const shared = { ...run, attemptId, href };
            if (run.result !== null) {
                if (
                    run.finished_at === null ||
                    opened.has(attemptId) ||
                    Date.parse(run.finished_at) + 60_000 <= now
                ) {
                    return [];
                }
                return [
                    {
                        ...shared,
                        lifecycle: 'finished',
                        outcome: runOutcome(run.result),
                        state: {
                            check_id: run.check_id,
                            phase: 'finished',
                            queue_position: null,
                            progress_n: null,
                            progress_total: null,
                            started_at: run.run.started_at,
                            actor: run.run.actor,
                        },
                    },
                ];
            }
            const state = currentRunState(live.get(run.check_id), run.run);
            if (!state) return [];
            // Finished since the last read: the own run shows its result until the shared data catches up.
            if (state.phase === 'finished') {
                return run.mine && state.result && state.result !== 'pending'
                    ? [
                          {
                              ...shared,
                              lifecycle: 'finished',
                              outcome: runOutcome(state.result),
                              state,
                          },
                      ]
                    : [];
            }
            return [
                {
                    ...shared,
                    lifecycle: runLifecycle(state.phase),
                    outcome: undefined,
                    state,
                },
            ];
        });
    }, [data, live, now]);

    // An own result leaves the shell about a minute after it finished.
    const nextExpiry = Math.min(
        ...(data?.check_runs ?? []).flatMap((run) =>
            run.finished_at !== null && run.result !== null
                ? [Date.parse(run.finished_at) + 60_000]
                : [],
        ),
    );
    useEffect(() => {
        if (!Number.isFinite(nextExpiry) || nextExpiry <= now) return;
        const timer = window.setTimeout(
            () => setNow(Date.now()),
            nextExpiry - Date.now() + 50,
        );
        return () => window.clearTimeout(timer);
    }, [nextExpiry, now]);

    const settled = (data?.check_runs ?? []).filter(
        (run) => run.result === null,
    ).length;
    const stillActive = runs.filter(
        (run) => run.result === null && run.lifecycle !== 'finished',
    ).length;
    return {
        runs,
        activeCount: Math.max(
            0,
            (data?.active_check_runs ?? 0) - (settled - stillActive),
        ),
    };
}
