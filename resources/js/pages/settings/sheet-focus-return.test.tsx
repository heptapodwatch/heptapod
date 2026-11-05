import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ApiTokens from '@/pages/settings/api-tokens';
import Members from '@/pages/settings/members';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        props: { csrfToken: 'token', locale: 'en', timezone: 'UTC' },
    }),
    router: { on: () => () => {} },
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

/** Opens the page's create sheet from its header button by keyboard and closes it with Escape. */
async function openAndClose(opener: HTMLElement, firstField: string) {
    opener.focus();
    fireEvent.click(opener);
    const field = await screen.findByLabelText(firstField);
    expect(document.activeElement).toBe(field);
    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await vi.waitFor(() => expect(document.activeElement).toBe(opener));
}

it('returns focus to Create token after the token sheet closes', async () => {
    render(
        <ApiTokens
            tokensPage={{
                tokens: [],
                abilities: [],
                presets: {
                    read: [],
                    write: [],
                    manage: [],
                    'agent-read': [],
                },
                clients: [],
                timezone: 'UTC',
            }}
        />,
    );
    await openAndClose(
        screen.getAllByRole('button', { name: /Create token/ })[0]!,
        'Name',
    );
});

it('returns focus to Invite after the invitation sheet closes', async () => {
    render(
        <Members
            roster={{
                members: [],
                invitations: [],
                clients: [],
                invite_roles: ['Member'],
            }}
        />,
    );
    await openAndClose(screen.getByRole('button', { name: 'Invite' }), 'Email');
});
