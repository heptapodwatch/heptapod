import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    ActionsOverflowMenu,
    PageHeaderAction,
} from '@/components/page-header-actions';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t }) };
});
vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
    useIsBelowSm: () => false,
}));
afterEach(cleanup);

/** A dialog opened from state, as every app dialog is (no DialogTrigger for Radix to refocus). */
function StateDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Keyboard shortcuts</DialogTitle>
                <DialogDescription>Single keys.</DialogDescription>
            </DialogContent>
        </Dialog>
    );
}

async function closeWithEscape() {
    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();
    // Radix moves focus on close from a zero-delay timer after the dialog unmounts.
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
}

it('returns focus to the button that opened a state-controlled dialog', async () => {
    function Page() {
        const [open, setOpen] = useState(false);
        return (
            <>
                <button type="button" onClick={() => setOpen(true)}>
                    Show shortcuts
                </button>
                <StateDialog open={open} onOpenChange={setOpen} />
            </>
        );
    }
    render(<Page />);
    const opener = screen.getByRole('button', { name: 'Show shortcuts' });
    opener.focus();
    fireEvent.click(opener);
    expect(screen.getByRole('dialog')).toBeTruthy();

    await closeWithEscape();
    expect(document.activeElement).toBe(opener);
});

it('returns focus to the last control outside overlays when the opener closed as the dialog opened', async () => {
    // The command palette's "Keyboard shortcuts" closes the palette and opens this dialog in one step.
    function Page() {
        const [palette, setPalette] = useState(false);
        const [shortcuts, setShortcuts] = useState(false);
        return (
            <>
                <button type="button" onClick={() => setPalette(true)}>
                    Search
                </button>
                <Dialog open={palette} onOpenChange={setPalette}>
                    <DialogContent>
                        <DialogTitle>Command palette</DialogTitle>
                        <DialogDescription>Commands.</DialogDescription>
                        <button
                            type="button"
                            onClick={() => {
                                setPalette(false);
                                setShortcuts(true);
                            }}
                        >
                            Keyboard shortcuts
                        </button>
                    </DialogContent>
                </Dialog>
                <StateDialog open={shortcuts} onOpenChange={setShortcuts} />
            </>
        );
    }
    render(<Page />);
    const search = screen.getByRole('button', { name: 'Search' });
    search.focus();
    fireEvent.click(search);
    const command = screen.getByRole('button', { name: 'Keyboard shortcuts' });
    command.focus();
    fireEvent.click(command);
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(
        screen.getByRole('dialog', { name: 'Keyboard shortcuts' }),
    ).toBeTruthy();

    await closeWithEscape();
    expect(document.activeElement).toBe(search);
});

it('returns focus to the More actions trigger when a menu item opened the dialog', async () => {
    function Page() {
        const [menu, setMenu] = useState(false);
        const [open, setOpen] = useState(false);
        return (
            <>
                <ActionsOverflowMenu open={menu} onOpenChange={setMenu}>
                    <PageHeaderAction onClick={() => setOpen(true)}>
                        Copy
                    </PageHeaderAction>
                </ActionsOverflowMenu>
                <StateDialog open={open} onOpenChange={setOpen} />
            </>
        );
    }
    render(<Page />);
    const trigger = screen.getByRole('button', { name: 'More actions' });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'Enter' });
    const item = await screen.findByRole('menuitem', { name: 'Copy' });
    item.focus();
    fireEvent.click(item);
    expect(screen.getByRole('dialog')).toBeTruthy();

    await closeWithEscape();
    expect(document.activeElement).toBe(trigger);
});

it('still closes on Escape after a card toolbar folds into More actions while the dialog is open', async () => {
    // A resize below 640 px mounts the toolbar's overflow menu after the copy fallback opened.
    function Page() {
        const [open, setOpen] = useState(true);
        const [folded, setFolded] = useState(false);
        const [menu, setMenu] = useState(false);
        return (
            <>
                <button type="button" onClick={() => setFolded(true)}>
                    Fold toolbar
                </button>
                {folded && (
                    <ActionsOverflowMenu open={menu} onOpenChange={setMenu}>
                        <PageHeaderAction>Download CSV</PageHeaderAction>
                    </ActionsOverflowMenu>
                )}
                <StateDialog open={open} onOpenChange={setOpen} />
            </>
        );
    }
    render(<Page />);
    act(() => {
        fireEvent.click(
            screen.getByRole('button', { name: 'Fold toolbar', hidden: true }),
        );
    });
    expect(
        screen.getByRole('button', { name: 'More actions', hidden: true }),
    ).toBeTruthy();

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();
});

it('returns focus to the card’s More actions when the opener folded into it while the dialog was open', async () => {
    // Copy at 1440 opens the clipboard fallback; a resize below 640 px replaces the toolbar buttons with More actions.
    function Page() {
        const [open, setOpen] = useState(false);
        const [folded, setFolded] = useState(false);
        const [menu, setMenu] = useState(false);
        return (
            <main>
                <div data-slot="card">
                    <div data-slot="card-title">Crawl findings</div>
                    {folded ? (
                        <ActionsOverflowMenu open={menu} onOpenChange={setMenu}>
                            <PageHeaderAction>Copy</PageHeaderAction>
                        </ActionsOverflowMenu>
                    ) : (
                        <button type="button" onClick={() => setOpen(true)}>
                            Copy
                        </button>
                    )}
                </div>
                <button type="button" onClick={() => setFolded(true)}>
                    Resize
                </button>
                <StateDialog open={open} onOpenChange={setOpen} />
            </main>
        );
    }
    render(<Page />);
    const copy = screen.getByRole('button', { name: 'Copy' });
    copy.focus();
    fireEvent.click(copy);
    act(() => {
        fireEvent.click(
            screen.getByRole('button', { name: 'Resize', hidden: true }),
        );
    });
    expect(copy.isConnected).toBe(false);

    await closeWithEscape();
    expect(document.activeElement).toBe(
        screen.getByRole('button', { name: 'More actions' }),
    );
});

it('falls back to the main landmark, never BODY, when the opener and its region are gone', async () => {
    function Page() {
        const [open, setOpen] = useState(false);
        const [shown, setShown] = useState(true);
        return (
            <main aria-label="Page">
                {shown && (
                    <section>
                        <button type="button" onClick={() => setOpen(true)}>
                            Show token
                        </button>
                    </section>
                )}
                <button type="button" onClick={() => setShown(false)}>
                    Remove
                </button>
                <StateDialog open={open} onOpenChange={setOpen} />
            </main>
        );
    }
    render(<Page />);
    const opener = screen.getByRole('button', { name: 'Show token' });
    opener.focus();
    fireEvent.click(opener);
    act(() => {
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove', hidden: true }),
        );
    });

    await closeWithEscape();
    expect(document.activeElement).toBe(screen.getByRole('main'));
    expect(document.activeElement).not.toBe(document.body);
});

/**
 * Inertia's event sequence for one visit, with the detail shapes its core dispatches (fireBeforeEvent … fireFinishEvent):
 * before → start → error (422) | success + navigate (new page) → finish.
 */
const inertia = {
    fire(name: string, detail: object) {
        document.dispatchEvent(
            new CustomEvent(`inertia:${name}`, { detail, cancelable: true }),
        );
    },
    visit(url: string, method = 'get', only: string[] = []) {
        return { url: new URL(url, window.location.href), method, only };
    },
    page(url: string, component: string) {
        return { url, component, props: {} };
    },
};

async function flushMicrotasks() {
    await act(() => Promise.resolve());
}

/** The page the app is on before each case: the check detail page. */
function onCheckPage() {
    inertia.fire('navigate', {
        page: inertia.page('/sites/1/checks/111', 'sites/checks/show'),
    });
}

function ConfigurePage({
    onCloseAutoFocus,
}: {
    onCloseAutoFocus?: (event: Event) => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <main>
            <button type="button" onClick={() => setOpen(true)}>
                Configure
            </button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent onCloseAutoFocus={onCloseAutoFocus}>
                    <DialogTitle>Check settings</DialogTitle>
                    <DialogDescription>Interval.</DialogDescription>
                </DialogContent>
            </Dialog>
        </main>
    );
}

function openConfigure() {
    const opener = screen.getByRole('button', { name: 'Configure' });
    opener.focus();
    fireEvent.click(opener);
    expect(screen.getByRole('dialog')).toBeTruthy();
    return opener;
}

it('returns focus to the opener after a rejected submit (422) on the same page', async () => {
    onCheckPage();
    render(<ConfigurePage />);
    const opener = openConfigure();
    const save = inertia.visit('/sites/1/checks/111/settings', 'put');
    inertia.fire('before', { visit: save });
    await flushMicrotasks();
    inertia.fire('start', { visit: save });
    inertia.fire('error', {
        errors: { frequency_value: 'The interval must be an integer.' },
    });
    inertia.fire('finish', { visit: save });

    await closeWithEscape();
    expect(document.activeElement).toBe(opener);
});

it('returns focus to the opener after a successful save that stays on the page', async () => {
    onCheckPage();
    render(<ConfigurePage />);
    const opener = openConfigure();
    const save = inertia.visit('/sites/1/checks/111/settings', 'put');
    inertia.fire('before', { visit: save });
    await flushMicrotasks();
    inertia.fire('start', { visit: save });
    // The redirect back renders the same page: same path and component.
    const same = inertia.page('/sites/1/checks/111', 'sites/checks/show');
    inertia.fire('success', { page: same });
    inertia.fire('navigate', { page: same });
    inertia.fire('finish', { visit: save });

    await closeWithEscape();
    expect(document.activeElement).toBe(opener);
});

it('still returns focus after a partial reload (live update) while the dialog was open', async () => {
    onCheckPage();
    render(<ConfigurePage />);
    const opener = openConfigure();
    const reload = inertia.visit('/sites/1/checks/111', 'get', [
        'overallHealth',
    ]);
    inertia.fire('before', { visit: reload });
    await flushMicrotasks();
    inertia.fire('start', { visit: reload });
    const same = inertia.page('/sites/1/checks/111', 'sites/checks/show');
    inertia.fire('success', { page: same });
    inertia.fire('navigate', { page: same });
    inertia.fire('finish', { visit: reload });

    await closeWithEscape();
    expect(document.activeElement).toBe(opener);
});

it('runs neither its own nor a custom restoration while the app leaves for another page', async () => {
    // The palette closes as its visit starts; its custom handler would put focus back on Search.
    onCheckPage();
    const custom = vi.fn((event: Event) => {
        event.preventDefault();
        screen.getByRole('button', { name: 'Configure' }).focus();
    });
    render(<ConfigurePage onCloseAutoFocus={custom} />);
    const opener = openConfigure();
    const visit = inertia.visit('/activity');
    inertia.fire('before', { visit });
    await flushMicrotasks();
    inertia.fire('start', { visit });

    await closeWithEscape();
    expect(custom).not.toHaveBeenCalled();
    expect(document.activeElement).not.toBe(opener);
    // The visit lands: the next close on the new page restores again.
    inertia.fire('navigate', {
        page: inertia.page('/activity', 'activity/index'),
    });
    inertia.fire('finish', { visit });
});

it('does not pull focus back after the page changed while the dialog was open', async () => {
    onCheckPage();
    render(<ConfigurePage />);
    const opener = openConfigure();
    inertia.fire('navigate', {
        page: inertia.page('/sites/1/checks/112', 'sites/checks/show'),
    });

    await closeWithEscape();
    expect(document.activeElement).not.toBe(opener);
});

it('ignores a hover prefetch of another page', async () => {
    onCheckPage();
    render(<ConfigurePage />);
    const opener = openConfigure();
    inertia.fire('before', {
        visit: { ...inertia.visit('/incidents'), prefetch: true },
    });
    await flushMicrotasks();

    await closeWithEscape();
    expect(document.activeElement).toBe(opener);
});
