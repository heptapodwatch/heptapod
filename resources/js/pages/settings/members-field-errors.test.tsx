import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
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
// The invite request was refused with field messages for the role and one client assignment.
vi.mock('@/hooks/use-http-request', async (importOriginal) => {
    const { useState } = await import('react');
    return {
        ...(await importOriginal<typeof import('@/hooks/use-http-request')>()),
        useHttpRequest: <T extends object>(initial: T) => {
            const [data, setData] = useState(initial);
            return {
                data,
                setData: (next: T) => setData(next),
                processing: false,
                transform: () => {},
                errors: {
                    role: 'The selected role is invalid.',
                    'client_ids.0': 'The selected client is unavailable.',
                },
                submit: () => new Promise(() => {}),
                get: () => new Promise(() => {}),
            };
        },
    };
});

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

it('ties the role and client access errors to their own controls in the invite sheet', async () => {
    render(
        <Members
            roster={{
                members: [],
                invitations: [],
                clients: [
                    {
                        id: 1,
                        name: 'Ardent Legal',
                        archived: false,
                        short_name: null,
                        accent_color: null,
                        avatar: null as never,
                        is_internal: false,
                    },
                ],
                invite_roles: ['Admin', 'Member'],
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Invite' }));
    await screen.findByLabelText('Email');

    const roles = screen.getByRole('radiogroup');
    expect(roles.getAttribute('aria-invalid')).toBe('true');
    expect(roles.getAttribute('aria-describedby')).toBe('invite-role-error');
    expect(document.getElementById('invite-role-error')?.textContent).toBe(
        'The selected role is invalid.',
    );

    const clients = screen.getByRole('combobox');
    expect(clients.getAttribute('aria-invalid')).toBe('true');
    expect(clients.getAttribute('aria-describedby')).toBe(
        'invite-clients-help invite-clients-error',
    );
    expect(document.getElementById('invite-clients-error')?.textContent).toBe(
        'The selected client is unavailable.',
    );
});
