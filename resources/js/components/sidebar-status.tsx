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
    type RunLifecycle,
    type RunOutcome,
    type RunProgress,
} from '@/components/run-family';
import { TooltipDetails } from '@/components/ui/tooltip';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { useFormat } from '@/hooks/use-format';
import { useRunningNow, useRunningWork } from '@/hooks/use-running-now';
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

/** One run row of the footer, whatever ran: a check run, an import or a backup. */
type FooterRun = {
    key: string;
    lifecycle: RunLifecycle;
    outcome: RunOutcome | undefined;
    progress: RunProgress | undefined;
    label: string;
    secondary: string | undefined;
    mine: boolean;
    href: string;
};

/** Every run row in footer order: the viewer's results first, then the viewer's runs in flight, then everyone else's. */
export function useFooterRuns(): { rows: FooterRun[]; total: number } {
    const { t } = useTranslation();
    const format = useFormat();
    const detail = useRunDetail();
    const { runs, activeCount } = useRunningNow();
    const work = useRunningWork();
    const checkRows = runs.map((run): FooterRun => ({
        key: `check:${run.attemptId}`,
        lifecycle: run.lifecycle,
        outcome: run.outcome,
        progress:
            run.lifecycle === 'running' ? runProgress(run.state) : undefined,
        label: `${t(checkTypes[run.check_type].label)} · ${run.site_name}`,
        secondary:
            run.lifecycle === 'finished'
                ? t(runOutcomeWord[run.outcome!])
                : detail(run.state, { narrow: true }),
        mine: run.mine,
        href: run.href,
    }));
    const workRows = work.map((item): FooterRun => ({
        key: item.key,
        lifecycle: item.lifecycle,
        outcome: item.outcome,
        progress: undefined,
        label: t(item.kind === 'import' ? 'run.import' : 'run.backup'),
        secondary:
            item.lifecycle === 'finished'
                ? t(runOutcomeWord[item.outcome!])
                : item.lifecycle === 'running' && item.at
                  ? t('run.since', { time: format.time(item.at) })
                  : undefined,
        mine: item.mine,
        href: item.href,
    }));
    const rank = (row: FooterRun) =>
        row.lifecycle === 'finished' ? 0 : row.mine ? 1 : 2;
    const rows = [...checkRows, ...workRows].sort((a, b) => rank(a) - rank(b));
    const shownChecks = checkRows.filter((row) => row.lifecycle !== 'finished');
    // Check runs beyond the shared page of runs count too; imports and the backup are all in the data.
    return {
        rows,
        total: rows.length + Math.max(0, activeCount - shownChecks.length),
    };
}

function RunRows({ rows, total }: { rows: FooterRun[]; total: number }) {
    const { t } = useTranslation();
    const shown = rows.slice(0, RUN_ROWS);
    const more = total - shown.length;
    const expanded = 'group-data-[collapsible=icon]:hidden';
    const lead = shown.find((row) => row.lifecycle === 'running') ?? shown[0];
    return (
        <>
            {shown.map((row) => (
                <StatusRow
                    key={row.key}
                    className={expanded}
                    leading={
                        <RunGlyph
                            phase={row.lifecycle}
                            progress={row.progress}
                            outcome={row.outcome}
                        />
                    }
                    label={row.label}
                    secondary={row.secondary}
                    trailing={row.mine ? <YouMarker /> : undefined}
                    href={row.href}
                    tooltip={row.label}
                />
            ))}
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
                                progress={lead.progress}
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
                                facts={shown.map((row) =>
                                    row.secondary
                                        ? `${row.label}: ${row.secondary}`
                                        : row.label,
                                )}
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
    const { rows, total } = useFooterRuns();
    const health = useHealthRow();
    if (rows.length === 0 && !health && channels.length === 0) return null;
    // Only this summary is announced, never each progress tick.
    const count = (lifecycle: RunLifecycle) =>
        rows.filter((row) => row.lifecycle === lifecycle).length;
    const summary = [
        ...rows
            .filter((row) => row.lifecycle === 'finished')
            .map((row) => `${row.label}: ${row.secondary}`),
        ...(count('running')
            ? [t('run.summary.running', { count: count('running') })]
            : []),
        ...(count('queued')
            ? [t('run.summary.queued', { count: count('queued') })]
            : []),
    ].join(' · ');
    return (
        <>
            <div role="group" aria-label={t('run.statusGroup')}>
                <p role="status" aria-live="polite" className="sr-only">
                    {summary}
                </p>
                <SidebarMenu>
                    {rows.length > 0 && <RunRows rows={rows} total={total} />}
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
