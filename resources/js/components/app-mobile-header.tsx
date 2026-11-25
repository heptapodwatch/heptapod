import { usePage } from '@inertiajs/react';
import { BrandLink } from '@/components/app-logo';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { useTranslation } from 'react-i18next';
import { RunGlyph } from '@/components/run-family';
import { Button } from '@/components/ui/button';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { useFooterRuns } from '@/components/sidebar-status';

export function AppMobileHeader({
    onOpenSearch,
}: {
    onOpenSearch: (trigger: HTMLButtonElement) => void;
}) {
    const { props } = usePage();
    return (
        // Three columns keep the wordmark centred whether or not the runs glyph is shown.
        <header className="grid h-13 shrink-0 grid-cols-[1fr_auto_1fr] items-center gap-2 border-b px-4 md:hidden">
            <SidebarTrigger />
            <BrandLink className="[--brand-mark-h:19px]" tooltipSide="bottom" />
            <div className="flex items-center gap-1 justify-self-end">
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
    const { rows, total: count } = useFooterRuns();
    const lead = rows.find((row) => row.lifecycle === 'running') ?? rows[0];
    if (!lead) return null;
    return (
        <Button
            variant="ghost"
            className="h-10 gap-1.5 px-2"
            aria-label={`${t('Running now')}: ${count}`}
            onClick={() => setOpenMobile(true)}
        >
            <RunGlyph phase={lead.lifecycle} outcome={lead.outcome} />
            <span className="text-xs tabular-nums">{count}</span>
        </Button>
    );
}
