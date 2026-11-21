import { usePage } from '@inertiajs/react';
import { CircleHelp, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { CommandPaletteTrigger } from '@/components/real-command-palette';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from 'react-i18next';
import { BrandLink } from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { SidebarStatus } from '@/components/sidebar-status';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
    useSidebar,
} from '@/components/ui/sidebar';
import { appSidebarNavigation, visibleNavigation } from '@/lib/navigation';

function SidebarCollapseButton({
    tooltipSide,
}: {
    tooltipSide: 'right' | 'bottom';
}) {
    const { t } = useTranslation();
    const { state, toggleSidebar } = useSidebar();
    const collapsed = state === 'collapsed';
    const label = t(collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    data-sidebar="collapse-toggle"
                    aria-label={label}
                    onClick={toggleSidebar}
                    className="rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                >
                    {collapsed ? <PanelLeftOpen /> : <PanelLeftClose />}
                </Button>
            </TooltipTrigger>
            <TooltipContent side={tooltipSide} align="center">
                {label} <Kbd>⌘B</Kbd>
            </TooltipContent>
        </Tooltip>
    );
}

export function AppSidebar({
    onOpenSearch,
    onOpenShortcuts,
}: {
    onOpenSearch: (trigger: HTMLButtonElement) => void;
    onOpenShortcuts: () => void;
}) {
    const { t } = useTranslation();
    const { setOpenMobile, state, isMobile } = useSidebar();
    const { props } = usePage();
    const health = props.overallHealth;
    const collapsed = !isMobile && state === 'collapsed';
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
    const planningItems = items.filter((item) => item.group === 'Planning');
    const operationsItems = items.filter((item) => item.group === 'Operations');
    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            // Expanded: left tracks the centred shell and ::before carries bg-sidebar to the left viewport edge.
            // Collapsed: pins flush to left-0 as a 3rem icon rail; border-r is the single consistent 1px hairline.
            className="left-[max(0px,calc((100%-var(--shell-width))/2))] group-data-[collapsible=icon]:left-0 before:absolute before:inset-y-0 before:right-full before:w-screen before:bg-sidebar"
        >
            <SidebarHeader className="group-data-[collapsible=icon]:gap-1">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <div className="flex items-center gap-1 group-data-[collapsible=icon]:flex-col">
                            <SidebarMenuButton
                                size="lg"
                                asChild
                                className="min-w-0 flex-1 group-data-[collapsible=icon]:flex-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0!"
                            >
                                <BrandLink
                                    markOnly={!isMobile && state !== 'expanded'}
                                />
                            </SidebarMenuButton>
                            {/* Keyboard shortcuts do not apply on touch; the collapsed rail keeps the logo mark and toggle. */}
                            {!isMobile && (
                                <div className="flex shrink-0 items-center gap-0.5 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-1">
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground group-data-[collapsible=icon]:hidden hover:bg-transparent hover:text-foreground dark:hover:bg-transparent"
                                                aria-label={t(
                                                    'Keyboard shortcuts',
                                                )}
                                                aria-haspopup="dialog"
                                                onClick={onOpenShortcuts}
                                            >
                                                <CircleHelp />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent side="bottom">
                                            {t('Keyboard shortcuts')}{' '}
                                            <Kbd>?</Kbd>
                                        </TooltipContent>
                                    </Tooltip>
                                    <SidebarCollapseButton
                                        tooltipSide={
                                            collapsed ? 'right' : 'bottom'
                                        }
                                    />
                                </div>
                            )}
                        </div>
                    </SidebarMenuItem>
                </SidebarMenu>
                {props.auth.user && (
                    <CommandPaletteTrigger
                        collapsedTooltip={collapsed}
                        onClick={(trigger) => {
                            setOpenMobile(false);
                            onOpenSearch(trigger);
                        }}
                    />
                )}
            </SidebarHeader>
            <SidebarSeparator className="hidden group-data-[collapsible=icon]:block" />
            <SidebarContent>
                <NavMain
                    label={t('Main')}
                    items={items.filter((item) => item.group === 'Primary')}
                />
                {planningItems.length > 0 && (
                    <>
                        <SidebarSeparator className="hidden group-data-[collapsible=icon]:block" />
                        <NavMain
                            title={t('Planning & history')}
                            items={planningItems}
                        />
                    </>
                )}
                {operationsItems.length > 0 && (
                    <>
                        <SidebarSeparator className="hidden group-data-[collapsible=icon]:block" />
                        <NavMain
                            title={t('Operations')}
                            items={operationsItems}
                        />
                    </>
                )}
            </SidebarContent>
            <SidebarFooter>
                <SidebarStatus />
                <NavMain
                    className="p-0"
                    items={items.filter((item) => item.group === 'Footer')}
                />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
