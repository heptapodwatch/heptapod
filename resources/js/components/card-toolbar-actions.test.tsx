import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        i18n: { language: 'en' },
    }),
}));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.resetModules();
});

/** Loads the toolbar with the viewport matching (or not) the below-640 px query its hook reads at import. */
async function toolbarAt(width: number, primaryMode = false) {
    vi.stubGlobal(
        'matchMedia',
        (query: string) =>
            ({
                matches:
                    query === '(max-width: 639px)' ? width < 640 : width < 768,
                media: query,
                addEventListener: () => {},
                removeEventListener: () => {},
            }) as unknown as MediaQueryList,
    );
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    const { CardToolbarActions } = await import('./card-toolbar-actions');
    const { PageHeaderAction } = await import('./page-header-actions');
    const send = vi.fn();
    const copy = vi.fn();
    const primary = vi.fn();
    render(
        <CardToolbarActions
            outside={<p>fallback dialog</p>}
            primary={
                primaryMode ? (
                    <PageHeaderAction onClick={primary}>
                        Send test
                    </PageHeaderAction>
                ) : undefined
            }
        >
            <PageHeaderAction variant="outline" onClick={copy}>
                Copy
            </PageHeaderAction>
            <PageHeaderAction variant="outline" onClick={send}>
                Send to client…
            </PageHeaderAction>
        </CardToolbarActions>,
    );
    return { send, copy, primary };
}

it('shows every card action as a button from 640 px', async () => {
    const { send } = await toolbarAt(1440);
    expect(screen.queryByRole('button', { name: 'More actions' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Send to client…' }));
    expect(send).toHaveBeenCalledOnce();
    expect(screen.getByText('fallback dialog')).toBeTruthy();
});

it('keeps the primary visible and fires the overflow menu actions at 1440 px', async () => {
    const { send, copy, primary } = await toolbarAt(1440, true);

    fireEvent.click(screen.getByRole('button', { name: 'Send test' }));
    expect(primary).toHaveBeenCalledOnce();
    expect(screen.queryByRole('button', { name: 'Copy' })).toBeNull();
    fireEvent.pointerDown(
        screen.getByRole('button', { name: 'More actions' }),
        {
            button: 0,
            ctrlKey: false,
        },
    );
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Copy' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Send to client…' }));

    expect(copy).toHaveBeenCalledOnce();
    expect(send).toHaveBeenCalledOnce();
});

it('folds the card actions into one More actions menu below 640 px', async () => {
    const { send } = await toolbarAt(390);
    expect(screen.queryByRole('button', { name: 'Copy' })).toBeNull();
    fireEvent.pointerDown(
        screen.getByRole('button', { name: 'More actions' }),
        {
            button: 0,
            ctrlKey: false,
        },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'Send to client…' }),
    );
    expect(send).toHaveBeenCalledOnce();
    expect(screen.getByRole('menuitem', { name: 'Copy' })).toBeTruthy();
    // What must survive the menu closing stays outside it.
    expect(screen.getByText('fallback dialog')).toBeTruthy();
});
