import { usePage } from '@inertiajs/react';
import { BrandLink } from '@/components/app-logo';
import { CommandPaletteTrigger } from '@/components/real-command-palette';
import { SidebarTrigger } from '@/components/ui/sidebar';

export function AppMobileHeader({
    onOpenSearch,
}: {
    onOpenSearch: (trigger: HTMLButtonElement) => void;
}) {
    const { props } = usePage();
    return (
        <header className="flex h-13 shrink-0 items-center justify-between gap-2 border-b px-4 md:hidden">
            <SidebarTrigger />
            <BrandLink className="[--brand-mark-h:19px]" />
            {props.auth.user && (
                <CommandPaletteTrigger compact onClick={onOpenSearch} />
            )}
        </header>
    );
}
