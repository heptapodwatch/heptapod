import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { NavigationEntry } from '@/lib/navigation';

export type ShortcutGroup = 'General' | 'Navigation' | 'Lists';

export type Shortcut = {
    /** Keys pressed in sequence; a modifier is written into the key ("⌘K"). */
    keys: string[];
    /** Translation key. */
    label: string;
    /** Interpolation values for the label. */
    values?: Record<string, string>;
    group: ShortcutGroup;
};

/** The element a list page marks as its create action; `C` activates it. */
export const createShortcutAttribute = 'data-shortcut-create';

/** Every keyboard shortcut, once. The `?` sheet and the command palette hints read this list. */
export function shortcutList(entries: NavigationEntry[]): Shortcut[] {
    return [
        { keys: ['⌘K'], label: 'Open command palette', group: 'General' },
        { keys: ['⌘B'], label: 'Toggle sidebar', group: 'General' },
        { keys: ['?'], label: 'Keyboard shortcuts', group: 'General' },
        { keys: ['Esc'], label: 'Close', group: 'General' },
        ...entries.flatMap((entry): Shortcut[] =>
            entry.shortcut
                ? [
                      {
                          keys: ['G', entry.shortcut.toUpperCase()],
                          label: 'Go to :page',
                          values: { page: entry.title },
                          group: 'Navigation',
                      },
                  ]
                : [],
        ),
        { keys: ['C'], label: 'Create on this page', group: 'Lists' },
        { keys: ['J'], label: 'Next site', group: 'Lists' },
        { keys: ['K'], label: 'Previous site', group: 'Lists' },
    ];
}

function typing(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            target.closest('input, textarea, select, [role="combobox"]') !==
                null)
    );
}

/** Open dialogs, sheets and menus own the keyboard. */
function overlayOpen(): boolean {
    return (
        document.querySelector(
            '[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"], [role="menu"][data-state="open"], [role="listbox"]',
        ) !== null
    );
}

const sequenceWindowMs = 1500;

/** Single-key shortcuts outside text fields: G then a key navigates, C creates, ? opens the shortcut sheet. */
export function useGlobalShortcuts({
    entries,
    onHelp,
}: {
    entries: NavigationEntry[];
    onHelp: () => void;
}): void {
    const pendingSince = useRef<number | null>(null);
    const latest = useRef({ entries, onHelp });
    latest.current = { entries, onHelp };
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                event.defaultPrevented ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                typing(event.target) ||
                overlayOpen()
            ) {
                pendingSince.current = null;
                return;
            }
            const key = event.key.toLowerCase();
            const pending = pendingSince.current;
            pendingSince.current = null;
            if (pending !== null && Date.now() - pending <= sequenceWindowMs) {
                const entry = latest.current.entries.find(
                    (candidate) => candidate.shortcut === key,
                );
                if (entry) {
                    event.preventDefault();
                    router.visit(entry.href);
                }
                return;
            }
            if (event.key === '?') {
                event.preventDefault();
                latest.current.onHelp();
            } else if (key === 'g' && !event.shiftKey) {
                pendingSince.current = Date.now();
            } else if (key === 'c' && !event.shiftKey) {
                const create = document.querySelector<HTMLElement>(
                    `[${createShortcutAttribute}]`,
                );
                if (create && create.getClientRects().length > 0) {
                    event.preventDefault();
                    create.click();
                }
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);
}
