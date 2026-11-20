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
import { show as importRun } from '@/routes/settings/import/ohdear/runs';
import { edit as updatesPage } from '@/routes/settings/updates';
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

/** Own finished runs whose page this tab has opened; they leave the shell (UI rules, Runs). Keys: `check:<attempt>`, … */
function openedRuns(): Set<string> {
    return new Set(
        JSON.parse(sessionStorage.getItem(openedKey) ?? '[]') as string[],
    );
}

function markOpened(key: string) {
    const opened = openedRuns();
    if (opened.has(key)) return;
    opened.add(key);
    sessionStorage.setItem(openedKey, JSON.stringify([...opened]));
}

/** An own result shows for about a minute after it finished (RunningNowReader::OWN_RESULT_SECONDS). */
const OWN_RESULT_MS = 60_000;

export const importOutcome: Record<
    'completed' | 'incomplete' | 'failed',
    RunOutcome
> = { completed: 'ok', incomplete: 'warning', failed: 'failed' };
export const backupOutcome: Record<'complete' | 'failed', RunOutcome> = {
    complete: 'ok',
    failed: 'failed',
};

/** An import or a backup in the shell: in flight, or the viewer's own result of the last minute. */
export type ShellWork = {
    key: string;
    kind: 'import' | 'backup';
    lifecycle: RunLifecycle;
    outcome: RunOutcome | undefined;
    mine: boolean;
    /** In flight: when it started; finished: when it finished. */
    at: string | null;
    href: string;
};

/** Imports and the backup of the shared Running now data, in the order the reader sent them. */
export function useRunningWork(): ShellWork[] {
    const { props, url } = usePage();
    const data = props.runningNow;
    const path = new URL(url, window.location.origin).pathname;
    const [now, setNow] = useState(Date.now);
    const work = useMemo((): ShellWork[] => {
        const items: ShellWork[] = [
            ...(data?.imports ?? []).map((run): ShellWork => {
                const finished =
                    run.status === 'completed' ||
                    run.status === 'incomplete' ||
                    run.status === 'failed';
                return {
                    key: `import:${run.id}`,
                    kind: 'import',
                    lifecycle: finished
                        ? 'finished'
                        : run.status === 'running'
                          ? 'running'
                          : 'queued',
                    outcome: finished ? importOutcome[run.status] : undefined,
                    mine: run.mine,
                    at: run.finished_at ?? run.started_at,
                    href: importRun.url(run.id),
                };
            }),
            ...(data?.backup
                ? [
                      ((backup): ShellWork => {
                          const finished =
                              backup.state === 'complete' ||
                              backup.state === 'failed';
                          return {
                              key: `backup:${backup.id}`,
                              kind: 'backup',
                              lifecycle: finished
                                  ? 'finished'
                                  : backup.state === 'running'
                                    ? 'running'
                                    : 'queued',
                              outcome: finished
                                  ? backupOutcome[backup.state]
                                  : undefined,
                              mine: backup.mine,
                              at: backup.completed_at ?? backup.started_at,
                              href: updatesPage.url(),
                          };
                      })(data.backup),
                  ]
                : []),
        ];
        const opened = openedRuns();
        return items.filter(
            (item) =>
                item.lifecycle !== 'finished' ||
                (!opened.has(item.key) &&
                    item.at !== null &&
                    Date.parse(item.at) + OWN_RESULT_MS > now),
        );
    }, [data, now]);
    // An own result whose page is open counts as opened.
    useEffect(() => {
        work.forEach((item) => {
            if (item.lifecycle === 'finished' && item.href === path) {
                markOpened(item.key);
                setNow(Date.now());
            }
        });
    }, [work, path]);
    const nextExpiry = Math.min(
        ...work.flatMap((item) =>
            item.lifecycle === 'finished' && item.at
                ? [Date.parse(item.at) + OWN_RESULT_MS]
                : [],
        ),
    );
    useEffect(() => {
        if (!Number.isFinite(nextExpiry)) return;
        const timer = window.setTimeout(
            () => setNow(Date.now()),
            Math.max(0, nextExpiry - Date.now()) + 50,
        );
        return () => window.clearTimeout(timer);
    }, [nextExpiry]);
    return work;
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
                markOpened(`check:${run.run.attempt_id}`);
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
                    opened.has(`check:${attemptId}`) ||
                    Date.parse(run.finished_at) + OWN_RESULT_MS <= now
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
                ? [Date.parse(run.finished_at) + OWN_RESULT_MS]
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
