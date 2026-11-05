import { Link, usePage } from '@inertiajs/react';
import { StatusDot } from '@/components/monitoring';
import { CommandPaletteTrigger } from '@/components/real-command-palette';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { HealthIndicator } from '@/components/foundation-primitives';
import { useFormat } from '@/hooks/use-format';
import { useTranslation } from 'react-i18next';
import { BrandLink } from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
    useSidebar,
} from '@/components/ui/sidebar';
import { appSidebarNavigation, visibleNavigation } from '@/lib/navigation';
import { edit as systemHealth } from '@/routes/settings/system';

export function AppSidebar({
    onOpenSearch,
}: {
    onOpenSearch: (trigger: HTMLButtonElement) => void;
}) {
    const { t } = useTranslation();
    const { setOpenMobile, state, isMobile } = useSidebar();
    const format = useFormat();
    const { props } = usePage();
    const health = props.overallHealth;
    const noEmailRoute =
        health && health.active_checks > 0 && !health.notifications_covered;
    const items = visibleNavigation(props.auth)
        .filter((item) => appSidebarNavigation.includes(item))
        .map((item) => ({
            ...item,
            title: t(item.title),
            count:
                item.title === 'Incidents'
                    ? health?.open_incident_count || undefined
                    : item.title === 'Runs'
                      ? props.lateRunsCount || undefined
                      : undefined,
        }));
    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="border-r border-sidebar-border"
        >
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0!"
                        >
                            <BrandLink
                                markOnly={!isMobile && state !== 'expanded'}
                            />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {props.auth.user && (
                    <CommandPaletteTrigger
                        onClick={(trigger) => {
                            setOpenMobile(false);
                            onOpenSearch(trigger);
                        }}
                    />
                )}
            </SidebarHeader>
            <SidebarContent>
                <NavMain
                    label={t('Main')}
                    items={items.filter((item) => item.group === 'Primary')}
                />
                <NavMain
                    title={t('Planning & history')}
                    items={items.filter((item) => item.group === 'Planning')}
                />
                <NavMain
                    title={t('Operations')}
                    items={items.filter((item) => item.group === 'Operations')}
                />
            </SidebarContent>
            <SidebarFooter>
                {props.notificationAttention.length > 0 && (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Badge
                                variant="outline"
                                className="min-h-8 max-w-full gap-2"
                                role="status"
                                tabIndex={0}
                                aria-label={t('Channels need attention')}
                            >
                                <StatusDot
                                    state="warning"
                                    label="Channels need attention"
                                />
                                <span className="min-w-0 truncate group-data-[collapsible=icon]:hidden">
                                    {t('Channels need attention')}
                                </span>
                                <span className="ml-auto shrink-0 font-mono tabular-nums group-data-[collapsible=icon]:hidden">
                                    {props.notificationAttention.length}
                                </span>
                            </Badge>
                        </TooltipTrigger>
                        <TooltipContent>
                            <TooltipDetails
                                subject={t('Notifications')}
                                facts={props.notificationAttention.map(
                                    (item) => `${item.label}: ${item.reason}`,
                                )}
                                status={{
                                    state: 'warning',
                                    label: t('Channels need attention'),
                                }}
                            />
                        </TooltipContent>
                    </Tooltip>
                )}
                {health &&
                    (health.status !== 'ok' ||
                        health.system_issue_status !== null ||
                        noEmailRoute ||
                        (health.site_count > 0 &&
                            health.active_checks === 0)) && (
                        <ShellHealth
                            health={health}
                            noEmailRoute={!!noEmailRoute}
                            updatedAt={
                                health.dispatcher_at
                                    ? format.relative(health.dispatcher_at)
                                    : '—'
                            }
                        />
                    )}
                <NavMain
                    items={items.filter((item) => item.group === 'Footer')}
                />
                <NavUser />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}

/** Monitoring problems win over system issues; system issues link to System health, where each one is explained. */
function ShellHealth({
    health,
    noEmailRoute,
    updatedAt,
}: {
    health: Heptapod.Data.Ui.ShellHealthData;
    noEmailRoute: boolean;
    updatedAt: string;
}) {
    const { t } = useTranslation();
    const nothingChecked = health.site_count > 0 && health.active_checks === 0;
    const systemOnly =
        !noEmailRoute &&
        !nothingChecked &&
        health.status === 'ok' &&
        health.system_issue_status !== null;
    const indicator = (
        <HealthIndicator
            state={
                noEmailRoute || nothingChecked
                    ? 'warning'
                    : health.status === 'down' ||
                        (systemOnly && health.system_issue_status === 'down')
                      ? 'check-error'
                      : health.status === 'warn' || systemOnly
                        ? 'warning'
                        : 'ok'
            }
            label={
                noEmailRoute
                    ? t('No alert route')
                    : nothingChecked
                      ? t('Nothing is checked')
                      : systemOnly
                        ? t('System issues: :count', {
                              count: health.system_issue_count,
                          })
                        : health.status === 'ok'
                          ? t('Monitoring healthy')
                          : health.status === 'down'
                            ? t('Monitoring stopped')
                            : t('Needs attention')
            }
            updatedAt={updatedAt}
            updatedAtDateTime={health.dispatcher_at ?? ''}
        />
    );
    return systemOnly ? (
        <Link href={systemHealth.url()} className="rounded-md">
            {indicator}
        </Link>
    ) : (
        indicator
    );
}
