import { Link } from '@inertiajs/react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    activeNavigationEntry,
    appSidebarNavigation,
    type NavigationEntry,
} from '@/lib/navigation';
import { useFormat } from '@/hooks/use-format';

export function NavMain({
    items,
    title,
    label,
}: {
    items: (NavigationEntry & { disabled?: boolean; count?: number })[];
    title?: string;
    /** Accessible name for an untitled group; defaults to Settings. */
    label?: string;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const { currentUrl } = useCurrentUrl();
    // Resolve among app-sidebar entries only: every /settings/* page except the Operations ones activates Settings.
    const active = activeNavigationEntry(appSidebarNavigation, currentUrl);
    const { setOpenMobile } = useSidebar();
    const labelId = useId();
    return (
        <SidebarGroup>
            {title && (
                <SidebarGroupLabel id={labelId}>{title}</SidebarGroupLabel>
            )}
            <SidebarGroupContent>
                <nav
                    aria-labelledby={title ? labelId : undefined}
                    aria-label={title ? undefined : (label ?? t('Settings'))}
                >
                    <SidebarMenu>
                        {items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                {item.disabled ? (
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span className="block">
                                                <SidebarMenuButton
                                                    className="min-h-10 text-muted-foreground sm:min-h-0"
                                                    aria-disabled="true"
                                                >
                                                    {item.icon && <item.icon />}
                                                    <span>{item.title}</span>
                                                </SidebarMenuButton>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent side="right">
                                            {t('Coming soon')}
                                        </TooltipContent>
                                    </Tooltip>
                                ) : (
                                    <SidebarMenuButton
                                        asChild
                                        className="min-h-10 sm:min-h-0"
                                        isActive={active?.href === item.href}
                                        tooltip={item.title}
                                    >
                                        <Link
                                            href={item.href}
                                            aria-current={
                                                active?.href === item.href
                                                    ? 'location'
                                                    : undefined
                                            }
                                            aria-label={
                                                item.title === t('Upcoming') &&
                                                item.count !== undefined
                                                    ? t('Upcoming items', {
                                                          count: item.count,
                                                      })
                                                    : undefined
                                            }
                                            prefetch
                                            onSuccess={() =>
                                                setOpenMobile(false)
                                            }
                                        >
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                            {item.count !== undefined && (
                                                <span
                                                    className={
                                                        item.title ===
                                                            t('Incidents') &&
                                                        item.count > 0
                                                            ? 'ml-auto font-mono text-xs text-[var(--status-down)]'
                                                            : 'ml-auto font-mono text-xs text-muted-foreground'
                                                    }
                                                >
                                                    {format.number(item.count)}
                                                </span>
                                            )}
                                        </Link>
                                    </SidebarMenuButton>
                                )}
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </nav>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
