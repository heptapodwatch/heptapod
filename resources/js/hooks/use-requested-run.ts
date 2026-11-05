import type { Page } from '@inertiajs/core';
import { useEffect, useRef, useState } from 'react';
import { onLiveSignal, useStreamState, type RunState } from '@/lib/live-store';
import { show as attemptShow } from '@/routes/sites/checks/attempts';

/** What the run.finished signal of this tab's own attempt reported. */
export type OwnRunResult = {
    attemptId: number;
    outcome: NonNullable<RunState['outcome']>;
    // Null when the attempt ended without storing a run (cancelled or skipped).
    checkRunId: number | null;
    endedAt: string | null;
    result: Heptapod.Contracts.CheckResult | null;
};

/** CheckRunAttemptController response. */
type AttemptState = {
    attempt_id: number;
    phase: Heptapod.Contracts.RunPhase;
    terminal: boolean;
    check_run_id: number | null;
    ended_at: string | null;
    result: Heptapod.Contracts.CheckResult | null;
};

type RequestedRunState =
    | { phase: 'idle' }
    | { phase: 'requesting' }
    | { phase: 'running'; attemptId: number }
    | { phase: 'finished'; run: OwnRunResult };

function ownRunResult(run: RunState, attemptId: number): OwnRunResult {
    if (run.outcome === undefined) {
        throw new Error('A finished run signal carries no outcome.');
    }
    return {
        attemptId,
        outcome: run.outcome,
        checkRunId: run.check_run_id ?? null,
        endedAt: run.ended_at ?? null,
        result: run.result ?? null,
    };
}

/**
 * Tracks the "Run check" this tab requested by the attempt id the POST response flashes. Only the run.finished
 * signal of that attempt completes it; other runs of the check (scheduled, other tabs) never do. `finished` then
 * holds the result until the page calls `consume`. The page waits until its props contain this run (the live
 * reload after the signal), lets its buffered views accept exactly the rows of this run, then consumes it.
 */
export function useRequestedRun(siteId: number, checkId: number | null) {
    const [state, setState] = useState<RequestedRunState>({ phase: 'idle' });
    const current = useRef(state);
    current.current = state;
    // A fast run can finish before the POST response names its attempt.
    const finishedBeforeAccept = useRef(new Map<number, OwnRunResult>());

    const settle = (run: OwnRunResult) => setState({ phase: 'finished', run });

    useEffect(() => {
        if (checkId === null) return;
        const unsubscribe = onLiveSignal((signal) => {
            const run = signal.run;
            if (
                signal.topic !== 'run.finished' ||
                run === undefined ||
                run.check_id !== checkId ||
                run.attempt_id === undefined
            ) {
                return;
            }
            const state = current.current;
            if (
                state.phase === 'running' &&
                state.attemptId === run.attempt_id
            ) {
                settle(ownRunResult(run, run.attempt_id));
            } else if (state.phase === 'requesting') {
                finishedBeforeAccept.current.set(
                    run.attempt_id,
                    ownRunResult(run, run.attempt_id),
                );
            }
        });
        return () => {
            unsubscribe();
        };
    }, [checkId]);

    // A run.finished sent while this tab was disconnected is never replayed. After every reconnect and every
    // live data reload, a still-running attempt is read back from its durable state by its id.
    const { reconnected, dataFrom } = useStreamState();
    const runningAttemptId = state.phase === 'running' ? state.attemptId : null;
    useEffect(() => {
        if (runningAttemptId === null || checkId === null) return;
        const controller = new AbortController();
        void (async () => {
            const response = await fetch(
                attemptShow.url({
                    site: siteId,
                    check: checkId,
                    attempt: runningAttemptId,
                }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            );
            if (!response.ok) {
                throw new Error(`Run attempt state failed: ${response.status}`);
            }
            const attempt = (await response.json()) as AttemptState;
            const state = current.current;
            if (
                !attempt.terminal ||
                state.phase !== 'running' ||
                state.attemptId !== attempt.attempt_id
            ) {
                return;
            }
            settle({
                attemptId: attempt.attempt_id,
                outcome: attempt.phase as OwnRunResult['outcome'],
                checkRunId: attempt.check_run_id,
                endedAt: attempt.ended_at,
                result: attempt.result,
            });
        })().catch((error: unknown) => {
            if (!controller.signal.aborted) throw error;
        });
        return () => controller.abort();
    }, [runningAttemptId, reconnected, dataFrom]);

    return {
        state,
        finished: state.phase === 'finished' ? state.run : null,
        request: () => {
            finishedBeforeAccept.current.clear();
            setState({ phase: 'requesting' });
        },
        /** Reads the attempt id from the successful POST response. */
        accept: (page: Page) => {
            const attemptId = (page.flash as Record<string, unknown>)
                .requested_run_attempt_id;
            if (typeof attemptId !== 'number') {
                throw new Error('Run request response names no attempt.');
            }
            const early = finishedBeforeAccept.current.get(attemptId);
            finishedBeforeAccept.current.clear();
            setState({ phase: 'running', attemptId });
            if (early) settle(early);
        },
        abandon: () => setState({ phase: 'idle' }),
        consume: () => setState({ phase: 'idle' }),
    };
}

export type RequestedRun = ReturnType<typeof useRequestedRun>;
