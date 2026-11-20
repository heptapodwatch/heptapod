import { Link, usePage } from '@inertiajs/react';
import { Ellipsis } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import {
    RunGlyph,
    runOutcomeWord,
    runProgress,
    useRunDetail,
    YouMarker,
} from '@/components/run-family';
import { TooltipDetails } from '@/components/ui/tooltip';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { useFormat } from '@/hooks/use-format';
import { useRunningNow, type RunningCheckRun } from '@/hooks/use-running-now';
import { checkTypes } from '@/lib/check-types';
import { index as runsIndex } from '@/routes/runs';
import {
    edit as ownNotifications,
    team as teamNotifications,
} from '@/routes/settings/notifications';
import { edit as systemHealth } from '@/routes/settings/system';

/** At most three run rows; the rest is one "N more · Runs" row (UI rules, Sidebar footer). */
const RUN_ROWS = 3;

/** Quiet status rows: information, not destinations. No hover surface; hover underlines the label. */
const quietRow =
    'group/status text-xs text-muted-foreground hover:bg-transparent hover:text-foreground active:bg-transparent';

function StatusRow({
    leading,
    label,
    secondary,
    trailing,
    href,
    tooltip,
    className,
}: {
    leading: ReactNode;
    label: string;
    secondary?: string;
    trailing?: ReactNode;
    href?: string;
    /** The collapsed rail's tooltip; an object keeps it visible when expanded too. */
    tooltip: string | { children: ReactNode; hidden?: boolean };
    className?: string;
}) {
    const body = (
        <>
            <span className="flex size-4 shrink-0 items-center justify-center">
                {leading}
            </span>
            <span className="min-w-0 flex-1 truncate underline-offset-2 group-hover/status:underline">
                {label}
            </span>
            {secondary !== undefined && (
                <span className="shrink-0 tabular-nums">{secondary}</span>
            )}
            {trailing}
        </>
    );
    return (
        <SidebarMenuItem className={className}>
            <SidebarMenuButton asChild tooltip={tooltip} className={quietRow}>
                {href ? (
                    <Link href={href}>{body}</Link>
                ) : (
                    <span tabIndex={0}>{body}</span>
                )}
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

export const manualRunsHref = runsIndex.url({ query: { started: 'manual' } });

function useRunRowText() {
    const { t } = useTranslation();
    const detail = useRunDetail();
    return (run: RunningCheckRun) => ({
        label: `${t(checkTypes[run.check_type].label)} · ${run.site_name}`,
        secondary:
            run.lifecycle === 'finished'
                ? t(runOutcomeWord[run.outcome!])
                : detail(run.state, { narrow: true }),
    });
}

function RunRows({ runs, total }: { runs: RunningCheckRun[]; total: number }) {
    const { t } = useTranslation();
    const text = useRunRowText();
    const shown = runs.slice(0, RUN_ROWS);
    const more = total - shown.length;
    const expanded = 'group-data-[collapsible=icon]:hidden';
    const lead = shown.find((run) => run.lifecycle === 'running') ?? shown[0];
    return (
        <>
            {shown.map((run) => {
                const row = text(run);
                return (
                    <StatusRow
                        key={run.attemptId}
                        className={expanded}
                        leading={
                            <RunGlyph
                                phase={run.lifecycle}
                                progress={
                                    run.lifecycle === 'running'
                                        ? runProgress(run.state)
                                        : undefined
                                }
                                outcome={run.outcome}
                            />
                        }
                        label={row.label}
                        secondary={row.secondary}
                        trailing={run.mine ? <YouMarker /> : undefined}
                        href={run.href}
                        tooltip={row.label}
                    />
                );
            })}
            {more > 0 && (
                <StatusRow
                    className={expanded}
                    leading={<Ellipsis className="size-4" />}
                    label={t('run.more', { count: more })}
                    secondary={t('Runs')}
                    href={manualRunsHref}
                    tooltip={t('Runs')}
                />
            )}
            {/* Collapsed rail: one glyph with the count, linking to Runs; the tooltip lists up to three. */}
            {lead && (
                <StatusRow
                    className="hidden group-data-[collapsible=icon]:block"
                    leading={
                        <span className="relative inline-flex">
                            <RunGlyph
                                phase={lead.lifecycle}
                                progress={
                                    lead.lifecycle === 'running'
                                        ? runProgress(lead.state)
                                        : undefined
                                }
                                outcome={lead.outcome}
                            />
                            <span className="absolute -top-2 -right-2.5 rounded-full bg-foreground px-1 text-micro leading-4 text-background tabular-nums">
                                {total}
                            </span>
                        </span>
                    }
                    label={t('Running now')}
                    href={manualRunsHref}
                    tooltip={{
                        children: (
                            <TooltipDetails
                                subject={t('Running now')}
                                facts={shown.map((run) => {
                                    const row = text(run);
                                    return row.secondary
                                        ? `${row.label}: ${row.secondary}`
                                        : row.label;
                                })}
                            />
                        ),
                    }}
                />
            )}
        </>
    );
}

/** Same precedence as before: monitoring problems win over system issues. Null while all is well. */
function useHealthRow() {
    const { t } = useTranslation();
    const format = useFormat();
    const { overallHealth: health, auth } = usePage().props;
    if (!health) return null;
    const noEmailRoute =
        health.active_checks > 0 && !health.notifications_covered;
    const nothingChecked = health.site_count > 0 && health.active_checks === 0;
    const systemOnly =
        !noEmailRoute &&
        !nothingChecked &&
        health.status === 'ok' &&
        health.system_issue_status !== null;
    if (
        health.status === 'ok' &&
        health.system_issue_status === null &&
        !noEmailRoute &&
        !nothingChecked
    ) {
        return null;
    }
    return {
        state:
            noEmailRoute || nothingChecked
                ? ('warning' as const)
                : health.status === 'down' ||
                    (systemOnly && health.system_issue_status === 'down')
                  ? ('check-error' as const)
                  : ('warning' as const),
        label: noEmailRoute
            ? t('No alert route')
            : nothingChecked
              ? t('Nothing is checked')
              : systemOnly
                ? t('System issues: :count', {
                      count: health.system_issue_count,
                  })
                : health.status === 'down'
                  ? t('Monitoring stopped')
                  : t('Needs attention'),
        updatedAt: health.dispatcher_at
            ? format.relative(health.dispatcher_at)
            : undefined,
        href:
            systemOnly && auth.canViewSystemHealth
                ? systemHealth.url()
                : undefined,
    };
}

/**
 * The sidebar footer's status group (UI rules, Sidebar footer): runs, system health, channels, each only while true.
 * Renders nothing, not even its separator, when there is nothing to report.
 */
export function SidebarStatus() {
    const { t } = useTranslation();
    const { notificationAttention: channels, auth } = usePage().props;
    const { runs, activeCount } = useRunningNow();
    const health = useHealthRow();
    const finished = runs.filter((run) => run.lifecycle === 'finished');
    const total = activeCount + finished.length;
    if (runs.length === 0 && !health && channels.length === 0) return null;
    const running = runs.filter((run) => run.lifecycle === 'running').length;
    const queued = runs.filter((run) => run.lifecycle === 'queued').length;
    // Only this summary is announced, never each progress tick.
    const summary = [
        ...finished.map(
            (run) =>
                `${t(checkTypes[run.check_type].label)} · ${run.site_name}: ${t(runOutcomeWord[run.outcome!])}`,
        ),
        ...(running ? [t('run.summary.running', { count: running })] : []),
        ...(queued ? [t('run.summary.queued', { count: queued })] : []),
    ].join(' · ');
    return (
        <>
            <div role="group" aria-label={t('run.statusGroup')}>
                <p role="status" aria-live="polite" className="sr-only">
                    {summary}
                </p>
                <SidebarMenu>
                    {runs.length > 0 && <RunRows runs={runs} total={total} />}
                    {health && (
                        <StatusRow
                            leading={<StatusDot state={health.state} />}
                            label={health.label}
                            secondary={health.updatedAt}
                            href={health.href}
                            tooltip={health.label}
                        />
                    )}
                    {channels.length > 0 && (
                        <StatusRow
                            leading={<StatusDot state="warning" />}
                            label={t('Channels need attention')}
                            secondary={String(channels.length)}
                            href={
                                auth.canManageChannels
                                    ? teamNotifications.url()
                                    : ownNotifications.url()
                            }
                            tooltip={{
                                hidden: false,
                                children: (
                                    <TooltipDetails
                                        subject={t('Notifications')}
                                        facts={channels.map(
                                            (item) =>
                                                `${item.label}: ${item.reason}`,
                                        )}
                                        status={{
                                            state: 'warning',
                                            label: t('Channels need attention'),
                                        }}
                                    />
                                ),
                            }}
                        />
                    )}
                </SidebarMenu>
            </div>
            <SidebarSeparator className="mx-0" />
        </>
    );
}
