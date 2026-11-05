import { cn } from '@/lib/utils';
import { listCardPadding } from '@/components/ui/table';
import { AbsoluteTime } from '@/components/absolute-time';
import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { useTranslation } from 'react-i18next';
import { CalendarClock } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { TasksApiNotice } from '@/components/tasks-api-notice';
import { EvidenceFacts } from '@/components/evidence-facts';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    MonitoringTime,
    StatusBadge,
    statusColor,
} from '@/components/monitoring';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormat } from '@/hooks/use-format';
import { parseResult, resultState, statusLabels } from '@/lib/status';
import { rotateUrl } from '@/routes/sites/checks/tasks';

function taskState(result: string) {
    return resultState(parseResult(result));
}

export function CronTasksDetail({
    page,
    history,
}: {
    page: {
        can_run: boolean;
        site: Pick<Heptapod.Data.Ui.SiteRowData, 'id'>;
        check: Pick<Heptapod.Data.Ui.CheckStripData, 'id'>;
    };
    history: Heptapod.Addons.Cron.CronTasksDetailData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Scheduled tasks')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
                <TasksApiNotice canManage={page.can_run} />
                {history.more_tasks && (
                    <p role="status" className="text-muted-foreground">
                        {t('Showing first 50 tasks')}
                    </p>
                )}
                {history.history_truncated && (
                    <p role="status" className="text-muted-foreground">
                        {t('Task history is incomplete')}
                    </p>
                )}
                {history.tasks.length ? (
                    <ul className="divide-y rounded-lg border">
                        {history.tasks.map((task) => (
                            <li
                                key={task.id}
                                className={cn('space-y-3', listCardPadding)}
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <strong className="min-w-0 break-words">
                                        {task.name}
                                    </strong>
                                    <StatusBadge
                                        state={taskState(task.result)}
                                    />
                                </div>
                                <EvidenceFacts
                                    rows={[
                                        {
                                            label: t('Schedule'),
                                            mono: true,
                                            value: (
                                                <>
                                                    {task.cron_expression ??
                                                        (task.frequency_minutes !==
                                                        null
                                                            ? format.intervalPhrase(
                                                                  task.frequency_minutes *
                                                                      60,
                                                              )
                                                            : '—')}
                                                    {task.timezone &&
                                                        ` · ${task.timezone}`}{' '}
                                                    · {t('Grace')}{' '}
                                                    {format.number(
                                                        task.grace_minutes,
                                                    )}{' '}
                                                    {t('min')}
                                                </>
                                            ),
                                        },
                                        {
                                            label: t('Last ping'),
                                            mono: true,
                                            value: (
                                                <>
                                                    {task.last_ping_at ? (
                                                        <MonitoringTime
                                                            value={
                                                                task.last_ping_at
                                                            }
                                                        />
                                                    ) : (
                                                        '—'
                                                    )}
                                                    {task.last_duration_seconds !==
                                                        null &&
                                                        ` · ${format.duration(task.last_duration_seconds)}`}
                                                </>
                                            ),
                                        },
                                        {
                                            label: t('Next due'),
                                            mono: true,
                                            value: (
                                                <AbsoluteTime
                                                    value={task.next_due_at}
                                                />
                                            ),
                                        },
                                        {
                                            label: t('Ping URL'),
                                            mono: true,
                                            value: (
                                                <span data-ping-url={task.id}>
                                                    {task.ping_url}
                                                </span>
                                            ),
                                        },
                                    ]}
                                />
                                {page.can_run && (
                                    <RotatePingUrl
                                        url={rotateUrl.url({
                                            site: page.site.id,
                                            check: page.check.id,
                                            task: task.id,
                                        })}
                                    />
                                )}
                                {task.days.length > 0 && (
                                    <div className="space-y-1">
                                        <p className="text-xs text-muted-foreground">
                                            {t('Retained result days')}
                                        </p>
                                        <TaskHistoryDays task={task} />
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <EmptyState
                        bare
                        icon={CalendarClock}
                        title={t('No tasks configured')}
                        description={t('cron.tasks.empty')}
                    />
                )}
            </CardContent>
        </Card>
    );
}

/** Rotating kills the old ping URL, so the confirmation is destructive. */
function RotatePingUrl({ url }: { url: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    return (
        <>
            <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                {t('Rotate ping URL')}
            </Button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                destructive
                title={t('Rotate ping URL?')}
                description={t(
                    'The old URL will stop working. Update your schedules.',
                )}
                confirmLabel={t('Rotate ping URL')}
                onConfirm={() =>
                    confirmVisit((callbacks) =>
                        router.post(
                            url,
                            {},
                            { preserveScroll: true, ...callbacks },
                        ),
                    )
                }
            />
        </>
    );
}

function TaskHistoryDays({
    task,
}: {
    task: Heptapod.Addons.Cron.CronTasksDetailData['tasks'][number];
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [focused, setFocused] = useState(task.days.length - 1);
    const triggers = useRef<(HTMLSpanElement | null)[]>([]);
    return (
        <div data-task-history className="flex flex-wrap gap-1">
            {task.days.map((day, index) => (
                <Tooltip key={day.day}>
                    <TooltipTrigger asChild>
                        <span
                            role="img"
                            ref={(element) => {
                                triggers.current[index] = element;
                            }}
                            tabIndex={
                                index ===
                                Math.min(focused, task.days.length - 1)
                                    ? 0
                                    : -1
                            }
                            onFocus={() => setFocused(index)}
                            onKeyDown={(event) => {
                                if (
                                    event.key !== 'ArrowLeft' &&
                                    event.key !== 'ArrowRight'
                                )
                                    return;
                                event.preventDefault();
                                triggers.current[
                                    Math.max(
                                        0,
                                        Math.min(
                                            task.days.length - 1,
                                            index +
                                                (event.key === 'ArrowRight'
                                                    ? 1
                                                    : -1),
                                        ),
                                    )
                                ]?.focus();
                            }}
                            aria-label={`${task.name} · ${day.day} · ${t(statusLabels[taskState(day.result)])}`}
                            style={{
                                backgroundColor:
                                    statusColor[taskState(day.result)],
                            }}
                            className="size-3 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </TooltipTrigger>
                    <TooltipContent>
                        <TooltipDetails
                            subject={task.name}
                            facts={[format.calendarDate(day.day)]}
                            status={{
                                state: taskState(day.result),
                                label: t(statusLabels[taskState(day.result)]),
                            }}
                            hint={t('Use arrow keys to inspect history.')}
                        />
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
