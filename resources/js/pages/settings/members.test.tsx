import { http, HttpResponseError } from '@inertiajs/core';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Members from '@/pages/settings/members';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ props: { csrfToken: 'token', timezone: 'UTC' } }),
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
beforeEach(() =>
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    ),
);
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('explains why the last active Owner cannot be edited and offers only Close', async () => {
    render(
        <Members
            roster={{
                clients: [],
                invitations: [],
                invite_roles: ['Member'],
                members: [
                    {
                        id: 1,
                        name: 'Last Owner',
                        email: 'owner@example.test',
                        role: 'Owner',
                        client_ids: [],
                        client_scope: 'all',
                        effective_client_scope: 'all',
                        last_active_at: null,
                        disabled: false,
                        is_last_owner: true,
                        can_edit: true,
                        can_deactivate: false,
                        can_reinvite: false,
                        allowed_roles: ['Owner'],
                    },
                ],
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

    const sheet = await screen.findByRole('dialog', { name: 'Last Owner' });
    const controls = within(sheet);
    expect(controls.getByRole('status').textContent).toBe(
        'The last active Owner cannot be demoted or removed.',
    );
    expect(
        controls
            .getAllByRole('button')
            .map((button) => button.textContent?.trim()),
    ).toEqual(['Close', 'Close']);
    fireEvent.click(controls.getAllByRole('button', { name: 'Close' })[0]!);
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
});

it('lets the server validate the invite email and shows its error at the field', async () => {
    const originalClient = http.getClient();
    const message = 'The Email field must be a valid email address.';
    http.setClient({
        request: async ({ url, method }) => {
            expect(url).toBe('/settings/members/invitations');
            expect(method).toBe('post');
            throw new HttpResponseError(
                'refused',
                {
                    status: 422,
                    headers: {},
                    data: JSON.stringify({ errors: { email: [message] } }),
                },
                url,
            );
        },
    });
    try {
        render(
            <Members
                roster={{
                    clients: [],
                    invitations: [],
                    members: [],
                    invite_roles: ['Admin'],
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invite' }));
        const sheet = within(
            await screen.findByRole('dialog', { name: 'Invite teammate' }),
        );
        const email = sheet.getByRole('textbox', { name: 'Email' });
        fireEvent.change(email, { target: { value: 'not-an-address' } });
        fireEvent.click(sheet.getByRole('button', { name: 'Invite teammate' }));
        expect((await sheet.findByRole('alert')).textContent).toBe(message);
        expect(email.getAttribute('aria-invalid')).toBe('true');
    } finally {
        http.setClient(originalClient);
    }
});
