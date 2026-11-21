import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    // Expanded: sidebar + the 80rem PageContainer form one centred block on wide screens.
    // Collapsed: the 3rem icon rail pins to the left viewport edge while PageContainer stays centred inside main.
    return (
        <SidebarProvider
            defaultOpen={isOpen}
            className="mx-auto max-w-(--shell-width) [--shell-width:calc(var(--sidebar-width)+80rem)] has-data-[collapsible=icon]:max-w-none"
        >
            {children}
        </SidebarProvider>
    );
}
