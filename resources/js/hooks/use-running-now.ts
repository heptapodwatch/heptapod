import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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

/**
 * One manual run of the shared Running now data: a check run, an import or a backup, in flight or the viewer's own
 * result of the last minute. `key` names the attempt: "Run again" on an import reuses its id, so its attempt counts too.
 */
export type ShellRun = {
    key: string;
    kind: 'check' | 'import' | 'backup';
    lifecycle: RunLifecycle;
    outcome: RunOutcome | undefined;
    mine: boolean;
    /** In flight: when it started; finished: when it finished. */
    at: string | null;
    href: string;
    /** Check runs only: the check and site. */
    check?: Heptapod.Data.Ui.RunningCheckRunData;
    /** Check runs in flight only: the live state (progress, start time) of exactly this attempt. */
    state?: RunState;
};

/** Every run in the shared data, live state merged in by attempt, before own results expire or are opened. */
export function shellRuns(
    data: Heptapod.Data.Ui.RunningNowData | null | undefined,
    live: ReadonlyMap<number, RunState>,
): ShellRun[] {
    if (!data) return [];
    const checks = data.check_runs.flatMap((run): ShellRun[] => {
        const attemptId = run.run.attempt_id;
        if (attemptId === null) {
            throw new Error('A running-now run must name its attempt.');
        }
        const shared = {
            key: `check:${attemptId}`,
            kind: 'check' as const,
            check: run,
            mine: run.mine,
            href: checkShow.url({ site: run.site_id, check: run.check_id }),
        };
        if (run.result !== null) {
            return [
                {
                    ...shared,
                    lifecycle: 'finished',
                    outcome: runOutcome(run.result),
                    at: run.finished_at,
                },
            ];
        }
        // The live map holds the latest signal per check; only this attempt's own signal may update its row, so a
        // scheduled run of the same check never takes the row (or its You) over.
        const signal = live.get(run.check_id);
        const state = currentRunState(
            signal?.attempt_id === attemptId ? signal : undefined,
            run.run,
        );
        if (!state) return [];
        if (state.phase === 'finished') {
            // Finished since the last read: the own run shows its result until the shared data catches up.
            return run.mine && state.result && state.result !== 'pending'
                ? [
                      {
                          ...shared,
                          lifecycle: 'finished',
                          outcome: runOutcome(state.result),
                          at: state.ended_at ?? state.at ?? null,
                      },
                  ]
                : [];
        }
        return [
            {
                ...shared,
                lifecycle: runLifecycle(state.phase),
                outcome: undefined,
                at: state.started_at,
                // The shared data never carries a queue position (it would cost a count of the whole queue per page).
                state: { ...state, queue_position: null },
            },
        ];
    });
    const imports = data.imports.map((run): ShellRun => {
        const finished =
            run.status === 'completed' ||
            run.status === 'incomplete' ||
            run.status === 'failed';
        return {
            key: `import:${run.id}:${run.attempts}`,
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
    });
    const backup = data.backup;
    const backups: ShellRun[] = backup
        ? [
              {
                  key: `backup:${backup.id}`,
                  kind: 'backup',
                  lifecycle:
                      backup.state === 'complete' || backup.state === 'failed'
                          ? 'finished'
                          : backup.state === 'running'
                            ? 'running'
                            : 'queued',
                  outcome:
                      backup.state === 'complete' || backup.state === 'failed'
                          ? backupOutcome[backup.state]
                          : undefined,
                  mine: backup.mine,
                  at: backup.completed_at ?? backup.started_at,
                  href: updatesPage.url(),
              },
          ]
        : [];
    return [...checks, ...imports, ...backups];
}

const openedKey = 'heptapod.running-now.opened';

function readOpened(): Set<string> {
    return new Set(
        JSON.parse(sessionStorage.getItem(openedKey) ?? '[]') as string[],
    );
}

/**
 * Shell runs as the footer and the mobile bar show them: an own result leaves once its page was opened in this tab
 * (at once, and still after a reload) or about a minute after it finished.
 */
export function useRunningNow(): { runs: ShellRun[]; activeChecks: number } {
    const { props, url } = usePage();
    const data = props.runningNow;
    const all = shellRuns(data, useStreamState('runs'));
    const path = new URL(url, window.location.origin).pathname;
    const [opened, setOpened] = useState(readOpened);
    const [now, setNow] = useState(Date.now);

    const openedHere = all
        .filter(
            (run) =>
                run.lifecycle === 'finished' &&
                run.href === path &&
                !opened.has(run.key),
        )
        .map((run) => run.key)
        .join(' ');
    useEffect(() => {
        if (openedHere === '') return;
        const next = new Set([...readOpened(), ...openedHere.split(' ')]);
        sessionStorage.setItem(openedKey, JSON.stringify([...next]));
        setOpened(next);
    }, [openedHere]);

    // Re-render at the next own result's expiry; expired ones never hold the timer back.
    const nextExpiry = Math.min(
        ...all
            .flatMap((run) =>
                run.lifecycle === 'finished' && run.at !== null
                    ? [Date.parse(run.at) + OWN_RESULT_MS]
                    : [],
            )
            .filter((at) => at > now),
    );
    useEffect(() => {
        if (!Number.isFinite(nextExpiry)) return;
        const timer = window.setTimeout(
            () => setNow(Date.now()),
            nextExpiry - Date.now() + 50,
        );
        return () => window.clearTimeout(timer);
    }, [nextExpiry]);

    const runs = all.filter(
        (run) =>
            run.lifecycle !== 'finished' ||
            (!opened.has(run.key) &&
                run.at !== null &&
                Date.parse(run.at) + OWN_RESULT_MS > now),
    );
    // Check runs beyond the shared page of rows count too; a row that finished since the read no longer does.
    const settled = data
        ? data.check_runs.filter((run) => run.result === null).length -
          all.filter((run) => run.kind === 'check' && run.state).length
        : 0;
    return {
        runs,
        activeChecks: Math.max(0, (data?.active_check_runs ?? 0) - settled),
    };
}
