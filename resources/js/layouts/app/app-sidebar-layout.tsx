import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppMobileHeader } from '@/components/app-mobile-header';
import { LiveProvider } from '@/components/live-provider';
import { KeyboardShortcutsDialog } from '@/components/keyboard-shortcuts-dialog';
import { RealCommandPalette } from '@/components/real-command-palette';
import { StreamLostStrip } from '@/components/stream-lost-strip';
import { useLiveReload } from '@/hooks/use-live';
import { useRef, useState, type ComponentProps } from 'react';
import { usePage } from '@inertiajs/react';
import { appSidebarNavigation, visibleNavigation } from '@/lib/navigation';
import { shortcutList, useGlobalShortcuts } from '@/lib/shortcuts';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    incidentBanner,
}: AppLayoutProps) {
    const { props } = usePage();
    const [searchOpen, setSearchOpen] = useState(false);
    const [shortcutsOpen, setShortcutsOpen] = useState(false);
    const destinations = visibleNavigation(props.auth).filter((entry) =>
        appSidebarNavigation.includes(entry),
    );
    const shortcuts = shortcutList(destinations);
    useGlobalShortcuts({
        entries: props.auth.user ? destinations : [],
        onHelp: () => setShortcutsOpen(true),
    });
    const paletteFocus = useRef<HTMLElement | SVGElement | null>(null);
    const rememberPaletteFocus = (element: Element | null) => {
        paletteFocus.current =
            element instanceof HTMLElement || element instanceof SVGElement
                ? element
                : null;
    };
    const openSearch = (trigger: HTMLButtonElement) => {
        rememberPaletteFocus(trigger);
        setSearchOpen(true);
    };
    const restorePaletteFocus: NonNullable<
        ComponentProps<typeof RealCommandPalette>['onCloseAutoFocus']
    > = (event) => {
        // The drawer search disappears on open; return to the mobile-bar search.
        // Navigation can unmount AppLayout, leaving no shell focus target.
        const opener = paletteFocus.current;
        const openerVisible =
            opener?.isConnected &&
            opener.getClientRects().length > 0 &&
            !opener.closest('[data-mobile="true"][data-state="closed"]');
        const target = openerVisible
            ? opener
            : document.querySelector<HTMLButtonElement>(
                  '[data-command-palette-trigger="mobile"]',
              );
        if (target && target.getClientRects().length > 0) {
            event.preventDefault();
            target.focus();
        }
    };
    return (
        <LiveProvider>
            <ShellHealthLive />
            <AppShell variant="sidebar">
                <AppSidebar onOpenSearch={openSearch} />
                <AppContent
                    variant="sidebar"
                    className="min-w-0 overflow-x-clip"
                >
                    <AppMobileHeader onOpenSearch={openSearch} />
                    <StreamLostStrip />
                    {incidentBanner}
                    {children}
                </AppContent>
                {props.auth.user && (
                    <RealCommandPalette
                        open={searchOpen}
                        onOpenChange={setSearchOpen}
                        onOpen={rememberPaletteFocus}
                        onCloseAutoFocus={restorePaletteFocus}
                        destinations={visibleNavigation(props.auth)}
                        onShowShortcuts={() => setShortcutsOpen(true)}
                    />
                )}
                <KeyboardShortcutsDialog
                    open={shortcutsOpen}
                    onOpenChange={setShortcutsOpen}
                    shortcuts={shortcuts}
                />
            </AppShell>
        </LiveProvider>
    );
}

/**
 * Keeps the shell's health (the mark's Down count, the sidebar badges) live on every page.
 * It reloads only the shared `overallHealth` prop, so page lists keep their own buffering.
 */
function ShellHealthLive() {
    useLiveReload(['state.changed'], ['overallHealth']);
    return null;
}
