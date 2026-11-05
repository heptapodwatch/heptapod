import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { http, router } from '@inertiajs/core';
import { useState } from 'react';
import { afterEach, beforeAll, expect, it, vi } from 'vitest';
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
afterEach(cleanup);

type PageObject = {
    component: string;
    url: string;
    props: Record<string, unknown>;
    version: string | null;
};

const checkPage = (errors: Record<string, string> = {}): PageObject => ({
    component: 'sites/checks/show',
    url: '/sites/1/checks/111',
    props: { errors },
    version: null,
});

/** The server: each request answers with the next queued Inertia page (status 200, the Inertia header). */
const responses: PageObject[] = [];
let releaseNext: (() => void) | null = null;
let holdNext = false;

beforeAll(() => {
    http.setClient({
        request: (config: { url: string }) =>
            new Promise((resolve) => {
                const page = responses.shift();
                if (!page)
                    throw new Error(`No response queued for ${config.url}`);
                const answer = () =>
                    resolve({
                        status: 200,
                        data: page,
                        headers: { 'x-inertia': 'true' },
                    });
                if (holdNext) {
                    holdNext = false;
                    releaseNext = answer;
                } else answer();
            }),
    } as never);
    // The real core router on the check page; components swap without rendering (the test renders its own tree).
    window.history.replaceState({}, '', '/sites/1/checks/111');
    router.init({
        initialPage: checkPage() as never,
        resolveComponent: () => ({}),
        swapComponent: async () => {},
    } as never);
});

async function settle() {
    await act(async () => {
        for (let i = 0; i < 10; i++) await new Promise((r) => setTimeout(r, 0));
    });
}

function Page({
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

function open() {
    const opener = screen.getByRole('button', { name: 'Configure' });
    opener.focus();
    fireEvent.click(opener);
    expect(screen.getByRole('dialog')).toBeTruthy();
    return opener;
}

async function escape() {
    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
}

it('(a) returns focus to Configure after the server refuses the save (422 redirect back with errors)', async () => {
    render(<Page />);
    const opener = open();
    responses.push(
        checkPage({ frequency_value: 'The interval must be an integer.' }),
    );
    router.put('/sites/1/checks/111/settings', {
        frequency_value: 'not-a-number',
    });
    await settle();

    await escape();
    expect(document.activeElement).toBe(opener);
});

it('(b) returns focus to Configure after a successful save that stays on the page', async () => {
    render(<Page />);
    const opener = open();
    responses.push(checkPage());
    router.put('/sites/1/checks/111/settings', { frequency_value: 5 });
    await settle();

    await escape();
    expect(document.activeElement).toBe(opener);
});

it('(c) neither restores nor runs a custom restoration while a visit to another page is in flight', async () => {
    // The command palette closes as router.visit starts; its handler would put focus back on Search.
    const custom = vi.fn((event: Event) => {
        event.preventDefault();
        screen.getByRole('button', { name: 'Configure' }).focus();
    });
    render(<Page onCloseAutoFocus={custom} />);
    const opener = open();
    responses.push({
        component: 'activity/index',
        url: '/activity',
        props: { errors: {} },
        version: null,
    });
    holdNext = true;
    router.visit('/activity');
    await settle();

    await escape();
    expect(custom).not.toHaveBeenCalled();
    expect(document.activeElement).not.toBe(opener);

    // The response lands; back on a page, the next dialog restores normally.
    await act(async () => releaseNext?.());
    await settle();
    responses.push(checkPage());
    router.visit('/sites/1/checks/111');
    await settle();
    cleanup();
    render(<Page />);
    const again = open();
    await escape();
    expect(document.activeElement).toBe(again);
});
