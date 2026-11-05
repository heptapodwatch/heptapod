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
    ConfirmDialog,
    confirmVisit,
    type ConfirmVisitCallbacks,
} from './confirm-dialog';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

afterEach(cleanup);

function Harness({
    onConfirm,
    destructive = false,
}: {
    onConfirm: () => Promise<boolean>;
    destructive?: boolean;
}) {
    const [open, setOpen] = useState(true);
    return (
        <>
            <span data-testid="state">{open ? 'open' : 'closed'}</span>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title="Rotate ping URL?"
                description="The old URL will stop working."
                confirmLabel="Rotate ping URL"
                destructive={destructive}
                onConfirm={onConfirm}
            />
        </>
    );
}

it('opens with focus on the safe Cancel button', () => {
    render(<Harness onConfirm={() => Promise.resolve(true)} />);
    expect(document.activeElement).toBe(
        screen.getByRole('button', { name: 'Cancel' }),
    );
});

it('cancels on Escape without confirming', () => {
    const onConfirm = vi.fn(() => Promise.resolve(true));
    render(<Harness onConfirm={onConfirm} />);
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(screen.getByTestId('state').textContent).toBe('closed');
    expect(onConfirm).not.toHaveBeenCalled();
});

it('submits once while the confirmation is pending, then closes', async () => {
    let finish: () => void = () => {};
    const onConfirm = vi.fn(
        () => new Promise<boolean>((resolve) => (finish = () => resolve(true))),
    );
    render(<Harness onConfirm={onConfirm} />);
    const confirm = screen.getByRole('button', { name: 'Rotate ping URL' });
    fireEvent.click(confirm);
    fireEvent.click(confirm);
    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(
        (
            screen.getByRole('button', {
                name: /Rotate ping URL/,
            }) as HTMLButtonElement
        ).disabled,
    ).toBe(true);
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(screen.getByTestId('state').textContent).toBe('open');
    await act(async () => finish());
    expect(screen.getByTestId('state').textContent).toBe('closed');
});

it('stays open and allows a retry when the confirmed action fails', async () => {
    const onConfirm = vi.fn(() => Promise.resolve(false));
    render(<Harness onConfirm={onConfirm} />);
    const confirm = screen.getByRole('button', { name: 'Rotate ping URL' });
    await act(async () => fireEvent.click(confirm));
    expect(screen.getByTestId('state').textContent).toBe('open');
    expect((confirm as HTMLButtonElement).disabled).toBe(false);
    await act(async () => fireEvent.click(confirm));
    expect(onConfirm).toHaveBeenCalledTimes(2);
});

it('uses the red button only for destructive confirmations', () => {
    const { unmount } = render(
        <Harness onConfirm={() => Promise.resolve(true)} destructive />,
    );
    expect(
        screen.getByRole('button', { name: 'Rotate ping URL' }).className,
    ).toContain('bg-destructive');
    unmount();
    render(<Harness onConfirm={() => Promise.resolve(true)} />);
    expect(
        screen.getByRole('button', { name: 'Rotate ping URL' }).className,
    ).not.toContain('bg-destructive');
});

// A fake Inertia visit: it fires only the named terminal callbacks, in Inertia's order (the callback, then onFinish).
function fakeVisit(...fired: (keyof ConfirmVisitCallbacks)[]) {
    return () =>
        confirmVisit((callbacks) => {
            queueMicrotask(() => fired.forEach((name) => callbacks[name]()));
        });
}

it.each([
    ['a network error', fakeVisit('onNetworkError', 'onFinish')],
    ['an HTTP exception', fakeVisit('onHttpException', 'onFinish')],
    ['a network error without onFinish', fakeVisit('onNetworkError')],
    ['an HTTP exception without onFinish', fakeVisit('onHttpException')],
])('leaves pending after %s, so Cancel works again', async (_, onConfirm) => {
    render(<Harness onConfirm={onConfirm} />);
    const confirm = screen.getByRole('button', { name: 'Rotate ping URL' });
    await act(async () => fireEvent.click(confirm));
    const cancel = screen.getByRole('button', { name: 'Cancel' });
    expect((confirm as HTMLButtonElement).disabled).toBe(false);
    expect((cancel as HTMLButtonElement).disabled).toBe(false);
    expect(screen.getByTestId('state').textContent).toBe('open');
    fireEvent.click(cancel);
    expect(screen.getByTestId('state').textContent).toBe('closed');
});

it('treats a success followed by onFinish as a success', async () => {
    render(<Harness onConfirm={fakeVisit('onSuccess', 'onFinish')} />);
    await act(async () =>
        fireEvent.click(
            screen.getByRole('button', { name: 'Rotate ping URL' }),
        ),
    );
    expect(screen.getByTestId('state').textContent).toBe('closed');
});

function OpenerHarness() {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>
                Pause site
            </button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title="Pause Shop?"
                description="Pausing stops checks and alerts."
                confirmLabel="Pause site"
                onConfirm={() => Promise.resolve(true)}
            />
        </>
    );
}

it('returns focus to the button that opened it after Escape', async () => {
    render(<OpenerHarness />);
    const opener = screen.getByRole('button', { name: 'Pause site' });
    opener.focus();
    fireEvent.click(opener);
    expect(document.activeElement).toBe(
        screen.getByRole('button', { name: 'Cancel' }),
    );
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();
    // Radix restores focus from a zero-delay timer after the dialog unmounts.
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(document.activeElement).toBe(opener);
});
