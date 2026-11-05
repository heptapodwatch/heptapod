import { EASE_OUT } from '@/lib/motion';
import { listCardPadding } from '@/components/ui/table';
import { SectionHeading } from '@/components/section-heading';
import { SiteAvatar } from '@/components/site-avatar';
import { Link } from '@inertiajs/react';
import { useConnectionStatus } from '@laravel/echo-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { RunBar } from '@/components/foundation-primitives';
import { StatusBadge, StatusDot } from '@/components/monitoring';
import { runState } from '@/lib/status';
import { ResultSummary } from '@/components/result-summary';
import { SegmentedControl } from '@/components/segmented-control';
import { Button } from '@/components/ui/button';
import { TextLink } from '@/components/text-link';
import { useFlip } from '@/hooks/use-flip';
import { useFormat } from '@/hooks/use-format';
import {
    useLiveRunBoard,
    type LiveRunBoardData,
} from '@/hooks/use-live-run-board';
import { useStreamState } from '@/lib/live-store';
import { checkTypes } from '@/lib/check-types';
import { cn } from '@/lib/utils';
import { show as checkShow } from '@/routes/sites/checks';
import { EntityLink, rowTargetClass } from '@/components/entity-link';

type Identity = Pick<
    Heptapod.Data.Ui.CurrentRunData,
    | 'check_id'
    | 'site_id'
    | 'site_name'
    | 'check_type'
    | 'icon_url'
    | 'icon_backing'
>;
export function RunResultChip({
    check,
    result,
}: {
    check: Heptapod.Contracts.CheckKey;
    result: Heptapod.Contracts.RunResult;
}) {
    return <StatusBadge state={runState(check, result)} />;
}

function RunBoardLiveStatus() {
    const { t } = useTranslation();
    const connection = useConnectionStatus();
    const { status, dataReloadFailed } = useStreamState();
    const live =
        connection === 'connected' &&
        status === 'connected' &&
        !dataReloadFailed;
    const [reloadAvailable, setReloadAvailable] = useState(false);
    useEffect(() => {
        setReloadAvailable(false);
        if (live) return;
        const timer = window.setTimeout(() => setReloadAvailable(true), 10_000);
        return () => window.clearTimeout(timer);
    }, [live]);
    return (
        <div
            data-run-live-status
            className="flex items-center gap-3 text-xs text-muted-foreground"
        >
            <span role="status" className="inline-flex items-center gap-2">
                <StatusDot
                    state={live ? 'ok' : 'warning'}
                    label={live ? 'Live' : 'Reconnecting…'}
                />
                {t(live ? 'Live' : 'Reconnecting…')}
            </span>
            {!live && reloadAvailable && (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => window.location.reload()}
                >
                    {t('runs.board.reload')}
                </Button>
            )}
        </div>
    );
}

type Column = 'queued' | 'running' | 'finished';
const columnLabels: Record<Column, string> = {
    queued: 'Queued',
    running: 'Running',
    finished: 'Finished',
};
function isColumn(value: string): value is Column {
    return Object.hasOwn(columnLabels, value);
}

function RunCard({
    row,
    flipKey,
    children,
}: {
    row: Identity;
    flipKey: string;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    return (
        <li data-flip-key={flipKey}>
            <Link
                href={checkShow.url({ site: row.site_id, check: row.check_id })}
                className={cn(
                    'block rounded-[10px] border bg-card transition-colors',
                    listCardPadding,
                    rowTargetClass,
                )}
            >
                <EntityLink
                    name={row.site_name}
                    insideLink
                    className="flex"
                    icon={
                        <SiteAvatar
                            name={row.site_name}
                            iconUrl={row.icon_url}
                            iconBacking={row.icon_backing}
                        />
                    }
                />
                <span className="mt-0.5 block text-xs text-muted-foreground">
                    {t(checkTypes[row.check_type].label)}
                </span>
                <span className="mt-2 block min-w-0">{children}</span>
            </Link>
        </li>
    );
}

function QueuedDetail({ run }: { run: Heptapod.Data.Ui.CheckRunStateData }) {
    const { t } = useTranslation();
    return (
        <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            {run.queue_position !== null && (
                <span className="font-mono tabular-nums">
                    {t('runs.board.position', {
                        position: run.queue_position,
                    })}
                </span>
            )}
            {run.actor !== null && (
                <span>{t('Requested by {{actor}}', { actor: run.actor })}</span>
            )}
        </span>
    );
}

function RunningDetail({ row }: { row: Heptapod.Data.Ui.CurrentRunData }) {
    const { t } = useTranslation();
    const format = useFormat();
    const { progress_n: done, progress_total: total } = row.run;
    const measured = done !== null && total !== null && total > 0;
    return (
        <span className="block space-y-1.5">
            <span className="flex flex-wrap items-center justify-between gap-2">
                <RunBar state="running" description={null} compact />
                {measured && (
                    <span className="font-mono text-xs text-muted-foreground tabular-nums">
                        {format.number(done)}/{format.number(total)}
                    </span>
                )}
            </span>
            <span
                role="progressbar"
                aria-label={t('runs.board.progress', {
                    check: t(checkTypes[row.check_type].label),
                    site: row.site_name,
                })}
                aria-valuemin={0}
                aria-valuemax={measured ? total : undefined}
                aria-valuenow={measured ? Math.min(done, total) : undefined}
                aria-valuetext={
                    measured ? undefined : t('runs.board.progressUnknown')
                }
                className="block h-1 overflow-hidden rounded-full bg-muted"
            >
                <span
                    className={cn(
                        'block h-full',
                        measured && 'bg-[var(--status-info)]',
                    )}
                    style={
                        measured
                            ? {
                                  width: `${Math.min(100, (done / total) * 100)}%`,
                              }
                            : {
                                  width: '100%',
                                  backgroundImage:
                                      'repeating-linear-gradient(135deg, var(--status-info), var(--status-info) 2px, transparent 2px, transparent 4px)',
                              }
                    }
                />
            </span>
            {row.run.actor !== null && (
                <span className="block text-xs text-muted-foreground">
                    {t('Requested by {{actor}}', { actor: row.run.actor })}
                </span>
            )}
        </span>
    );
}

function FinishedDetail({ row }: { row: Heptapod.Data.Ui.FinishedRunData }) {
    const format = useFormat();
    return (
        <span className="flex min-w-0 items-start gap-2 text-xs">
            <span className="mt-0.5 shrink-0">
                <StatusDot state={runState(row.check_type, row.result)} />
            </span>
            <span className="min-w-0 flex-1 text-muted-foreground">
                {row.summary !== null ? (
                    <ResultSummary
                        message={row.summary}
                        params={row.summary_params}
                        presentation={row.presentation}
                    />
                ) : (
                    <RunResultChip check={row.check_type} result={row.result} />
                )}
            </span>
            {row.duration_ms !== null && (
                <span className="shrink-0 font-mono text-muted-foreground tabular-nums">
                    {format.number(row.duration_ms)} ms
                </span>
            )}
        </span>
    );
}

/** The tab count grows briefly when a card arrives in the tab (a key it did not hold before). */
function TabCount({ count, keys }: { count: number; keys: string[] }) {
    const format = useFormat();
    const ref = useRef<HTMLSpanElement>(null);
    const previous = useRef<Set<string> | null>(null);
    const signature = keys.join(' ');
    useEffect(() => {
        const current = signature === '' ? [] : signature.split(' ');
        const before = previous.current;
        previous.current = new Set(current);
        if (
            before === null ||
            current.every((key) => before.has(key)) ||
            ref.current === null ||
            document.hidden ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        )
            return;
        ref.current.animate(
            { transform: ['scale(1)', 'scale(1.25)', 'scale(1)'] },
            { duration: 300, easing: EASE_OUT },
        );
    }, [signature]);
    return (
        <span ref={ref} className="ml-1.5 inline-block font-mono tabular-nums">
            {format.number(count)}
        </span>
    );
}

/** Finished cards on the board; the attempt history below holds the rest. */
const FINISHED_CARDS = 8;

/**
 * One lane per phase. On desktop the three lanes share one height (the tallest lane, at least six cards: 36rem), so a
 * card crossing the board moves sideways and the lanes read as one row of columns.
 */
function BoardColumn({
    column,
    count,
    note,
    active,
    empty,
    footer,
    children,
}: {
    column: Column;
    count: number;
    note?: string;
    active: boolean;
    empty: string | null;
    footer?: ReactNode;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <section
            data-run-column={column}
            aria-labelledby={`run-column-${column}`}
            className={cn(
                'min-w-0 flex-col gap-2 sm:flex',
                active ? 'flex' : 'hidden',
            )}
        >
            <div className="flex items-baseline justify-between gap-2 px-1">
                <SectionHeading id={`run-column-${column}`}>
                    {t(columnLabels[column])}{' '}
                    <span className="font-mono tabular-nums">
                        {format.number(count)}
                    </span>
                </SectionHeading>
                {note !== undefined && (
                    <span className="text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-2 rounded-[10px] border border-[var(--border-soft)] bg-muted/40 p-2 sm:min-h-[36rem]">
                {empty !== null ? (
                    <p className="px-2 py-3 text-xs text-muted-foreground">
                        {empty}
                    </p>
                ) : (
                    <ol className="flex flex-col gap-2">{children}</ol>
                )}
                {footer}
            </div>
        </section>
    );
}

export function RunBoard({
    data,
    filters,
}: {
    data: LiveRunBoardData;
    filters: Heptapod.Data.Ui.RunsFilterData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [tab, setTab] = useState<Column>('running');
    const { ref, flip } = useFlip<HTMLDivElement>();
    const scope = [
        filters.client,
        filters.check,
        filters.type,
        filters.state,
    ].join(':');
    const board = useLiveRunBoard(data, scope, flip);

    // A finished run keeps the card of the attempt that recorded it, so the card travels from Running.
    // Run IDs repeat across checks for imported runs, so the fallback key carries the physical identity.
    const finishedKey = (row: Heptapod.Data.Ui.FinishedRunData) =>
        row.attempt_id === null
            ? `run:${row.check_id}:${row.id}:${row.ended_at}`
            : `attempt:${row.attempt_id}`;
    // The server reconciles its reads; this guards a payload where an attempt is both active and
    // finished: the finished entry wins. The reader already orders Queued by position in each pool.
    const finishedAttempts = new Set(
        board.finished.map((row) => row.attempt_id).filter((id) => id !== null),
    );
    const active = board.current.filter(
        (row) =>
            row.run.attempt_id === null ||
            !finishedAttempts.has(row.run.attempt_id),
    );
    const queued = active.filter((row) => row.run.phase === 'queued');
    const finished = board.finished.slice(0, FINISHED_CARDS);
    const running = active.filter((row) => row.run.phase === 'running');
    const keys: Record<Column, string[]> = {
        queued: queued.map((row) => `attempt:${row.run.attempt_id}`),
        running: running.map((row) => `attempt:${row.run.attempt_id}`),
        finished: finished.map(finishedKey),
    };

    const counts: Record<Column, number> = {
        queued: queued.length + board.queued_more,
        running: running.length,
        finished: finished.length,
    };
    return (
        <section
            data-run-board
            aria-label={t('runs.board.label')}
            className="space-y-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="sm:hidden">
                    <SegmentedControl
                        label={t('runs.board.columns')}
                        value={tab}
                        onValueChange={(value) => {
                            if (!isColumn(value))
                                throw new Error('Unknown run column: ' + value);
                            setTab(value);
                        }}
                        options={(
                            ['queued', 'running', 'finished'] as const
                        ).map((column) => ({
                            value: column,
                            label: (
                                <>
                                    {t(columnLabels[column])}
                                    <TabCount
                                        count={counts[column]}
                                        keys={keys[column]}
                                    />
                                </>
                            ),
                        }))}
                    />
                </div>
                <div className="ml-auto flex items-center gap-3">
                    {board.pendingCount > 0 && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={board.apply}
                        >
                            {t(
                                board.pendingCount === 1
                                    ? '{{count}} new change ↑'
                                    : '{{count}} new changes ↑',
                                { count: board.pendingCount },
                            )}
                        </Button>
                    )}
                    <RunBoardLiveStatus />
                </div>
            </div>
            <div ref={ref} className="relative grid gap-4 sm:grid-cols-3">
                <BoardColumn
                    column="queued"
                    count={counts.queued}
                    active={tab === 'queued'}
                    empty={
                        queued.length === 0 ? t('runs.board.noQueued') : null
                    }
                >
                    {queued.map((row) => (
                        <RunCard
                            key={row.run.attempt_id}
                            row={row}
                            flipKey={`attempt:${row.run.attempt_id}`}
                        >
                            <QueuedDetail run={row.run} />
                        </RunCard>
                    ))}
                    {board.queued_more > 0 && (
                        <li className="px-1 text-xs text-muted-foreground">
                            {t('runs.board.moreQueued', {
                                count: board.queued_more,
                                formattedCount: format.number(
                                    board.queued_more,
                                ),
                            })}
                        </li>
                    )}
                </BoardColumn>
                <BoardColumn
                    column="running"
                    count={counts.running}
                    active={tab === 'running'}
                    empty={running.length === 0 ? t('runs.board.idle') : null}
                >
                    {running.map((row) => (
                        <RunCard
                            key={row.run.attempt_id}
                            row={row}
                            flipKey={`attempt:${row.run.attempt_id}`}
                        >
                            <RunningDetail row={row} />
                        </RunCard>
                    ))}
                </BoardColumn>
                <BoardColumn
                    column="finished"
                    count={counts.finished}
                    note={t('runs.board.finishedScope', {
                        count: FINISHED_CARDS,
                    })}
                    active={tab === 'finished'}
                    empty={
                        finished.length === 0
                            ? t('runs.board.noRecentFinished')
                            : null
                    }
                    footer={
                        board.finished.length > finished.length && (
                            <TextLink
                                document
                                href="#run-history"
                                variant="secondary"
                                className="mt-auto self-start px-1 py-1"
                            >
                                {t('runs.board.showHistory')}
                            </TextLink>
                        )
                    }
                >
                    {finished.map((row) => (
                        <RunCard
                            key={finishedKey(row)}
                            row={row}
                            flipKey={finishedKey(row)}
                        >
                            <FinishedDetail row={row} />
                        </RunCard>
                    ))}
                </BoardColumn>
            </div>
        </section>
    );
}
