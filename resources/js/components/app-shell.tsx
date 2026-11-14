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

    // Sidebar + the 80rem PageContainer form one block, centred once the viewport is wider; AppSidebar offsets its fixed column by the same width.
    return (
        <SidebarProvider
            defaultOpen={isOpen}
            className="mx-auto max-w-(--shell-width) [--shell-width:calc(var(--sidebar-width)+80rem)] has-data-[collapsible=icon]:[--shell-width:calc(var(--sidebar-width-icon)+80rem)]"
        >
            {children}
        </SidebarProvider>
    );
}
