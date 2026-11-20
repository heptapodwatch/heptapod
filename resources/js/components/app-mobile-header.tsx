import { usePage } from '@inertiajs/react';
import { BrandLink } from '@/components/app-logo';
import { CommandPaletteTrigger } from '@/components/real-command-palette';
import { useTranslation } from 'react-i18next';
import { RunGlyph } from '@/components/run-family';
import { Button } from '@/components/ui/button';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { useRunningNow } from '@/hooks/use-running-now';

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
            <div className="flex items-center gap-1">
                <MobileRunsTrigger />
                {props.auth.user && (
                    <CommandPaletteTrigger compact onClick={onOpenSearch} />
                )}
            </div>
        </header>
    );
}

/** While runs exist: one glyph with the count; it opens the drawer, whose footer lists them (UI rules, Sidebar footer). */
function MobileRunsTrigger() {
    const { t } = useTranslation();
    const { setOpenMobile } = useSidebar();
    const { runs, activeCount } = useRunningNow();
    const lead = runs.find((run) => run.lifecycle === 'running') ?? runs[0];
    if (!lead) return null;
    const count =
        activeCount + runs.filter((run) => run.lifecycle === 'finished').length;
    return (
        <Button
            variant="ghost"
            size="sm"
            className="h-10 gap-1.5 px-2"
            aria-label={`${t('Running now')}: ${count}`}
            onClick={() => setOpenMobile(true)}
        >
            <RunGlyph phase={lead.lifecycle} outcome={lead.outcome} />
            <span className="text-xs tabular-nums">{count}</span>
        </Button>
    );
}
