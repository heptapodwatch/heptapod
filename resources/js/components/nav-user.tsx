import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { UserMenuContent } from '@/components/user-menu-content';
export function NavUser() {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const { isMobile } = useSidebar();
    const getInitials = useInitials();
    const user = auth.user;
    if (!user) return null;
    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        {/* One footer row (UI rules, Sidebar footer): avatar, name, role as secondary, chevron. */}
                        <SidebarMenuButton
                            tooltip={user.name}
                            className="data-[state=open]:bg-sidebar-accent"
                            data-test="sidebar-menu-button"
                        >
                            <Avatar className="size-4 shrink-0 overflow-hidden rounded-full">
                                <AvatarImage src={user.avatar} alt="" />
                                <AvatarFallback className="bg-neutral-200 text-micro text-black dark:bg-neutral-700 dark:text-white">
                                    {getInitials(user.name).slice(0, 1)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="min-w-0 flex-1 truncate">
                                {user.name}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {user.roles
                                    .map((role) => t(role.name))
                                    .join(', ')}
                            </span>
                            <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="min-w-56"
                        side={isMobile ? 'bottom' : 'right'}
                        align="end"
                    >
                        <UserMenuContent user={user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
