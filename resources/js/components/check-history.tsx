import { History } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Disclosure } from '@/components/ui/disclosure';
import { PresentationChanges } from '@/components/presentation-changes';
import { useTranslation } from 'react-i18next';
import { MonitoringTime, StatusDot } from '@/components/monitoring';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { ListRow } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { runState } from '@/lib/status';
import type { ReactNode } from 'react';

type Run = Heptapod.Data.Ui.CheckRunData;

export function CheckHistory({
    page,
}: {
    page: Heptapod.Data.Ui.CheckTabPageData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const observations =
        page.history?.check_type === 'certificate_health'
            ? page.history.observations
            : [];
    const runs = page.runs;
    const visibleLabel = (run: Run): string => {
        const change = observations.find(
            (item) => item.run_id === run.id,
        )?.history_label;
        const part = change ?? run.presentation?.history_label;
        const text = part
            ? format.presentationPart(part)
            : run.presentation
              ? format.presentation(run.presentation)
              : run.summary
                ? format.summary(run.summary, run.summary_params)
                : t('No result');
        const dnsChange =
            page.history?.check_type === 'dns'
                ? page.history.timeline.find((item) => item.run_id === run.id)
                : null;
        return dnsChange?.initial ? t('First DNS snapshot') : text;
    };
    const label = (run: Run): ReactNode => {
        const text = visibleLabel(run);
        const dnsChange =
            page.history?.check_type === 'dns'
                ? page.history.timeline.find((item) => item.run_id === run.id)
                : null;
        if (dnsChange)
            return (
                <>
                    {text}
                    {!dnsChange.initial && dnsChange.diff_summary && (
                        <span className="block font-mono text-xs text-muted-foreground">
                            {dnsChange.diff_summary}
                        </span>
                    )}
                </>
            );
        const attempt =
            page.history?.check_type === 'application_health'
                ? page.history.attempts.find((item) => item.run_id === run.id)
                : null;
        return attempt ? (
            <>
                {text}
                {attempt.http_status !== null &&
                    ` · HTTP ${attempt.http_status}`}
                {attempt.response_time_ms !== null &&
                    ` · ${format.milliseconds(attempt.response_time_ms)} ${t('ms')}`}
                {attempt.reason && (
                    <span className="block text-xs text-muted-foreground">
                        {t(`health.reason.${attempt.reason}`)}
                    </span>
                )}
            </>
        ) : (
            text
        );
    };
    const streaks: {
        result: Run['result'];
        text: string;
        runs: [Run, ...Run[]];
    }[] = [];
    for (const run of runs) {
        const text = visibleLabel(run);
        const previous = streaks.at(-1);
        if (previous?.result === run.result && previous.text === text)
            previous.runs.push(run);
        else streaks.push({ result: run.result, text, runs: [run] });
    }
    type HistoryRow =
        | { kind: 'latest' | 'change'; run: Run }
        | { kind: 'unchanged'; run: Run; repeats: Run[] };
    const historyRows: HistoryRow[] = [];
    for (const [index, streak] of streaks.entries()) {
        const oldest = streak.runs.at(-1)!;
        if (index === 0)
            historyRows.push({ kind: 'latest', run: streak.runs[0] });
        const repeats = streak.runs.slice(index === 0 ? 1 : 0, -1);
        const firstRepeat = repeats[0];
        if (firstRepeat)
            historyRows.push({ kind: 'unchanged', run: firstRepeat, repeats });
        // A streak starts at its oldest observation; it must stay visible even when later runs repeat it.
        if (index !== 0 || streak.runs.length > 1)
            historyRows.push({ kind: 'change', run: oldest });
    }
    const row = (
        run: Run,
        text: ReactNode,
        time: ReactNode,
        action?: ReactNode,
    ) => (
        <ListRow className="grid-cols-[10px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 sm:grid-cols-[150px_10px_minmax(0,1fr)_auto]">
            <span className="col-start-2 col-end-4 font-mono text-xs text-[var(--foreground-faint)] tabular-nums sm:col-start-auto sm:col-end-auto">
                {time}
            </span>
            <span className="col-start-1 row-start-1 row-end-3 sm:col-start-auto sm:row-start-auto sm:row-end-auto">
                <StatusDot state={runState(page.check.key, run.result)} />
            </span>
            <span className="min-w-0 break-words">
                {text}
                {run.presentation && (
                    <span className="block">
                        <PresentationChanges
                            changes={run.presentation.changes}
                            details={run.presentation.details}
                        />
                    </span>
                )}
            </span>
            {action}
        </ListRow>
    );
    return (
        <Card className="overflow-hidden" data-check-history>
            <CardHeader>
                <CardTitle>
                    {t(
                        page.check.enabled
                            ? 'History'
                            : 'Before it was turned off: History',
                    )}
                </CardTitle>
            </CardHeader>
            {historyRows.length ? (
                <ol>
                    {historyRows.map((item) => (
                        <li
                            key={`${item.kind}:${item.run.id}`}
                            data-history-latest={item.run.ended_at}
                            data-history-kind={item.kind}
                            data-history-result={item.run.result}
                            className="border-b border-[var(--border-soft)] last:border-0"
                        >
                            {item.kind === 'unchanged' ? (
                                <Disclosure
                                    triggerClassName="pr-4 focus-visible:ring-inset"
                                    chevron="end"
                                    summary={row(
                                        item.run,
                                        <span className="text-muted-foreground">
                                            {t('check.history.unchanged', {
                                                count: item.repeats.length,
                                            })}
                                        </span>,
                                        format.historyRange(
                                            item.repeats.at(-1)!.ended_at,
                                            item.run.ended_at,
                                        ),
                                        <span className="text-xs">
                                            {t('Show')}
                                        </span>,
                                    )}
                                >
                                    <ol aria-label={t('Retained runs')}>
                                        {item.repeats.map((run) => (
                                            <li key={run.id}>
                                                {row(
                                                    run,
                                                    label(run),
                                                    <MonitoringTime
                                                        value={run.ended_at}
                                                    />,
                                                )}
                                            </li>
                                        ))}
                                    </ol>
                                </Disclosure>
                            ) : (
                                row(
                                    item.run,
                                    label(item.run),
                                    <MonitoringTime
                                        value={item.run.ended_at}
                                    />,
                                )
                            )}
                        </li>
                    ))}
                </ol>
            ) : (
                <CardContent>
                    <EmptyState
                        bare
                        icon={History}
                        title={t('No runs yet')}
                        description={t('check.history.empty')}
                    />
                </CardContent>
            )}
        </Card>
    );
}
