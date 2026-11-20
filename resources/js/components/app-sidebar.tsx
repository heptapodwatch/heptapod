import { usePage } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
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
    SidebarRail,
    useSidebar,
} from '@/components/ui/sidebar';
import { appSidebarNavigation, visibleNavigation } from '@/lib/navigation';

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
            // left snaps with the centred wrapper; only width animates, in step with the gap. The ::before fill carries the sidebar surface to the left viewport edge.
            className="left-[max(0px,calc((100%-var(--shell-width))/2))] border-r border-sidebar-border transition-[width] before:absolute before:inset-y-0 before:right-full before:w-screen before:bg-sidebar"
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
                        {/* Keyboard shortcuts do not apply on touch; the collapsed rail keeps only the logo mark. */}
                        {!isMobile && (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="absolute top-1/2 right-1 size-8 -translate-y-1/2 text-muted-foreground group-data-[collapsible=icon]:hidden hover:bg-transparent hover:text-foreground dark:hover:bg-transparent"
                                        aria-label={t('Keyboard shortcuts')}
                                        aria-haspopup="dialog"
                                        onClick={onOpenShortcuts}
                                    >
                                        <CircleHelp />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent side="bottom">
                                    {t('Keyboard shortcuts')} <Kbd>?</Kbd>
                                </TooltipContent>
                            </Tooltip>
                        )}
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
                <SidebarStatus />
                <NavMain
                    className="p-0"
                    items={items.filter((item) => item.group === 'Footer')}
                />
                <NavUser />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}
