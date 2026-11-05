import { useEffect, useRef, useState } from 'react';
import { motionAllowed } from '@/hooks/use-flip';
import { useLiveReload } from '@/hooks/use-live';

export type LiveRunBoardData = Heptapod.Data.Ui.RunsBoardData;

type RunColumn = 'queued' | 'running' | 'finished';
type HeldColumn = Exclude<RunColumn, 'finished'>;
const following: Record<HeldColumn, RunColumn> = {
    queued: 'running',
    running: 'finished',
};
const rank: Record<RunColumn, number> = { queued: 0, running: 1, finished: 2 };

/**
 * The shortest time a card stays in a column before it travels on. Presentation only: fast checks finish between two
 * board reloads, so without it their card jumped from Queued straight to Finished.
 */
const dwellMs: Record<HeldColumn, number> = { queued: 400, running: 1200 };

/** Time after the tab is visible again in which the resync reload (2 s coalesce plus the request) lands. */
const RESYNC_WINDOW_MS = 5000;

/** The column a card is shown in, since when, and its newest row from a column it was really in. */
type Stage = {
    column: RunColumn;
    since: number;
    row: Heptapod.Data.Ui.CurrentRunData | null;
};

/**
 * The board as shown. A card already on the board advances at most one column per dwell toward its real column, so
 * a run seen in Queued passes through Running before it reaches Finished; its Finished card then shows the real
 * result and duration. Each card is held for at most one dwell per column and every card has its own clock, so a
 * burst of fast runs is never dropped and never waits behind another. A card that first appears in a later column,
 * a run that left the board, and `dwell: false` (reduced motion, hidden tab) show the real state at once.
 */
function present(
    truth: LiveRunBoardData,
    previous: ReadonlyMap<string, Stage>,
    now: number,
    dwell: boolean,
): {
    board: LiveRunBoardData;
    stages: Map<string, Stage>;
    nextAt: number | null;
} {
    const real = new Map<
        string,
        { column: RunColumn; row: Heptapod.Data.Ui.CurrentRunData | null }
    >();
    for (const row of truth.current)
        real.set(`attempt:${row.run.attempt_id}`, {
            column: row.run.phase === 'queued' ? 'queued' : 'running',
            row,
        });
    // An attempt both active and finished (separate server reads) is finished.
    for (const row of truth.finished)
        if (row.attempt_id !== null)
            real.set(`attempt:${row.attempt_id}`, {
                column: 'finished',
                row: null,
            });

    const stages = new Map<string, Stage>();
    let nextAt: number | null = null;
    for (const [key, target] of real) {
        const before = previous.get(key);
        let stage: Stage;
        if (before === undefined || rank[before.column] > rank[target.column])
            stage = { column: target.column, since: now, row: target.row };
        else if (
            before.column === target.column ||
            before.column === 'finished'
        )
            stage = { ...before, row: target.row };
        else if (!dwell || now >= before.since + dwellMs[before.column])
            stage = {
                column: dwell ? following[before.column] : target.column,
                since: now,
                row: before.row,
            };
        else stage = before;
        if (stage.column === target.column)
            stage = { ...stage, row: target.row };
        else if (stage.column !== 'finished') {
            const releaseAt = stage.since + dwellMs[stage.column];
            nextAt = nextAt === null ? releaseAt : Math.min(nextAt, releaseAt);
        }
        stages.set(key, stage);
    }

    const shown = (
        row: Heptapod.Data.Ui.CurrentRunData,
    ): Heptapod.Data.Ui.CurrentRunData => {
        const stage = stages.get(`attempt:${row.run.attempt_id}`);
        if (stage === undefined || stage.row === null) return row;
        const phase: Heptapod.Contracts.RunPhase =
            stage.column === 'queued' ? 'queued' : 'running';
        return stage.row.run.phase === phase
            ? stage.row
            : {
                  ...stage.row,
                  run: { ...stage.row.run, phase, queue_position: null },
              };
    };
    const held = [...stages]
        .filter(([key, stage]) => stage.column !== real.get(key)?.column)
        .sort(([, a], [, b]) => a.since - b.since);
    const heldKeys = new Set(held.map(([key]) => key));
    // Held cards lead their column: they were queued or started before the cards that are there now.
    const current = [
        ...held.flatMap(([, stage]) =>
            stage.row === null ? [] : [shown(stage.row)],
        ),
        ...truth.current.filter(
            (row) =>
                !heldKeys.has(`attempt:${row.run.attempt_id}`) &&
                real.get(`attempt:${row.run.attempt_id}`)?.column !==
                    'finished',
        ),
    ];
    const finished = truth.finished.filter(
        (row) =>
            row.attempt_id === null ||
            stages.get(`attempt:${row.attempt_id}`)?.column === 'finished',
    );
    return {
        board: { current, queued_more: truth.queued_more, finished },
        stages,
        nextAt,
    };
}

/** Card identities and phases; a change here is a structural board change. */
function structure(board: LiveRunBoardData): Map<string, string> {
    return new Map([
        ...board.current.map(
            (row) =>
                [
                    `check:${row.check_id}`,
                    `${row.run.phase}:${row.run.attempt_id}`,
                ] as const,
        ),
        // Run IDs repeat across checks for imported runs: the physical identity tells them apart.
        ...board.finished.map(
            (row) =>
                [
                    `finished:${row.check_id}:${row.id}:${row.ended_at}`,
                    row.result,
                ] as const,
        ),
    ]);
}

function readingBoard(): boolean {
    const history = document.getElementById('run-history');
    const focus = document.activeElement;
    return (
        (history !== null &&
            window.scrollY > 0 &&
            history.getBoundingClientRect().top < window.innerHeight) ||
        (focus !== null && focus.closest('[data-run-history]') !== null) ||
        document.querySelector(
            '[data-run-history] details[open], [role="listbox"]:not([data-state="closed"]):not([hidden]), [role="menu"]:not([data-state="closed"]):not([hidden]), [role="dialog"]:not([data-state="closed"]):not([hidden])',
        ) !== null
    );
}

/**
 * Runs alone applies engine transitions live; history props never reload with the live zone.
 * Changes buffer behind "N new changes" only while the user reads the history or a menu is open.
 * `commit` wraps each applied change, so the board can animate it (FLIP); a card's minimum dwell (see `present`)
 * delays only its own move.
 * A hidden tab gets no reloads; useLiveReload reloads once the tab is visible again (resync). That resync and the
 * board on return show the server truth without dwell: a run that finished while hidden is already in Finished.
 */
export function useLiveRunBoard(
    data: LiveRunBoardData,
    scope: string,
    commit: (change: () => void) => void,
) {
    const { current, queued_more, finished } = data;
    const [shown, setShown] = useState<LiveRunBoardData>(data);
    const [pendingCount, setPendingCount] = useState(0);
    const latest = useRef<LiveRunBoardData>(data);
    const visible = useRef(data);
    const [initialStages] = useState(
        () => present(data, new Map(), Date.now(), false).stages,
    );
    const stages = useRef(initialStages);
    const release = useRef<number | undefined>(undefined);
    const previousScope = useRef(scope);
    /** The first change applied before this time skips the dwell: the resync after the tab is visible again. */
    const catchUpUntil = useRef(0);

    /** Shows `visible` with each card's dwell, and wakes again when the next held card may move. */
    const show = (dwell: boolean, animate = true) => {
        window.clearTimeout(release.current);
        const presented = present(
            visible.current,
            stages.current,
            Date.now(),
            dwell && motionAllowed(),
        );
        stages.current = presented.stages;
        if (animate) commit(() => setShown(presented.board));
        else setShown(presented.board);
        if (presented.nextAt !== null)
            release.current = window.setTimeout(
                () => show(true),
                Math.max(0, presented.nextAt - Date.now()),
            );
    };

    const apply = () => {
        visible.current = latest.current;
        setPendingCount(0);
        const resync = Date.now() < catchUpUntil.current;
        if (resync && !document.hidden) catchUpUntil.current = 0;
        show(!resync);
    };

    useEffect(() => {
        latest.current = { current, queued_more, finished };
        if (previousScope.current !== scope) {
            previousScope.current = scope;
            visible.current = latest.current;
            stages.current = new Map();
            setPendingCount(0);
            show(false, false);
            return;
        }
        const before = visible.current;
        if (
            before.current === current &&
            before.finished === finished &&
            before.queued_more === queued_more
        )
            return;
        const old = structure(before);
        const next = structure(latest.current);
        const changed = new Set([
            ...[...next]
                .filter(([key, value]) => old.get(key) !== value)
                .map(([key]) => key),
            ...[...old.keys()].filter((key) => !next.has(key)),
        ]);
        if (changed.size > 0 && readingBoard()) {
            setPendingCount(changed.size);
            return;
        }
        apply();
    }, [current, queued_more, finished, scope]);

    useEffect(() => {
        const onScroll = () => {
            if (visible.current !== latest.current && !readingBoard()) apply();
        };
        const onVisibility = () => {
            catchUpUntil.current = document.hidden
                ? Infinity
                : Date.now() + RESYNC_WINDOW_MS;
            if (!document.hidden) show(false);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        document.addEventListener('visibilitychange', onVisibility);
        return () => {
            window.removeEventListener('scroll', onScroll);
            document.removeEventListener('visibilitychange', onVisibility);
            window.clearTimeout(release.current);
        };
    }, []);

    useLiveReload(
        ['run.queued', 'run.started', 'run.progress', 'run.finished'],
        ['current', 'queued_more', 'finished'],
        undefined,
        true,
        { coalesceMs: 2000, pollFallback: false },
    );

    return { ...shown, pendingCount, apply };
}
