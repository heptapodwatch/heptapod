import { useFormat } from '@/hooks/use-format';
import { MonitoringTime } from '@/components/monitoring';
import { ArrowRight, CircleAlert, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function IncidentBanner({
    siteName,
    onOpen,
    started_at,
    duration_seconds,
    summary,
    designMode = false,
    cause = 'failed',
}: {
    siteName: string;
    onOpen?: () => void;
    started_at?: string;
    duration_seconds?: number;
    summary?: string | null;
    designMode?: boolean;
    cause?: 'failed' | 'errored';
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const isError = cause === 'errored';
    return (
        // Named exception (heptapod-ui-rules Callouts): the full-bleed incident strip is not a page callout.
        <div
            role="alert"
            data-slot="incident-banner"
            className={cn(
                'relative grid w-full grid-cols-[calc(var(--spacing)*4)_1fr] items-start gap-x-3 gap-y-0.5 px-4 py-3 text-sm [&>svg]:size-4 [&>svg]:translate-y-0.5',
                isError
                    ? 'border-b border-[var(--status-error)]/50 bg-[var(--status-error)]/10 text-foreground'
                    : 'bg-[var(--status-down)] text-status-down-foreground sm:flex sm:min-h-10 sm:items-center sm:gap-3',
            )}
        >
            {isError ? (
                <CircleAlert className="text-[var(--status-error)]!" />
            ) : (
                <TriangleAlert className="text-status-down-foreground!" />
            )}
            <div className="col-start-2 flex min-h-4 min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1 text-sm font-medium tracking-tight sm:shrink-0">
                <span className="min-w-0 truncate">
                    {isError
                        ? siteName
                        : t('{{site}} is down.', { site: siteName })}
                </span>
                {isError && (
                    <span className="shrink-0">
                        · {t('Check could not run')}
                    </span>
                )}
                {(started_at || designMode) && (
                    <span className="w-full font-mono whitespace-nowrap tabular-nums sm:w-auto">
                        {started_at ? (
                            <MonitoringTime
                                value={started_at}
                                mode="since"
                                prefix={t('Since')}
                            />
                        ) : (
                            t('since {{time}}', { time: '14:02' })
                        )}
                        {(duration_seconds !== undefined || designMode) && (
                            <> · {format.duration(duration_seconds ?? 840)}</>
                        )}
                    </span>
                )}
            </div>
            <div
                className={
                    isError
                        ? 'col-start-2 flex flex-wrap items-center justify-between gap-2 text-foreground'
                        : 'col-start-2 flex min-w-0 flex-1 flex-wrap items-center justify-between gap-2 text-status-down-foreground'
                }
            >
                <span className="min-w-0 truncate">
                    {designMode ? (
                        <>
                            {t('Uptime check failed')} ·{' '}
                            <span className="font-mono tabular-nums">
                                HTTP 503
                            </span>
                        </>
                    ) : (
                        summary
                    )}
                </span>
                {onOpen ? (
                    // Named exception: the shared accent tint and ring are invisible on the red strip.
                    <Button
                        variant="ghost"
                        className={
                            isError
                                ? undefined
                                : 'ml-auto shrink-0 text-status-down-foreground hover:bg-black/10 hover:text-status-down-foreground focus-visible:ring-current'
                        }
                        onClick={onOpen}
                    >
                        {t('Open incident')}{' '}
                        <ArrowRight aria-hidden="true" className="size-4" />
                    </Button>
                ) : (
                    <span className="ml-auto hidden text-xs opacity-80 sm:inline">
                        {t('You are on the incident page')}
                    </span>
                )}
            </div>
        </div>
    );
}
