import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { http } from '@inertiajs/core';
import type { HttpResponse } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import ClientSettings from '@/pages/clients/settings';
import { clients } from '@/fixtures/design';
import '@/lib/i18n';

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));

/** The move-blockers check's answer: the sites that cannot move right now. */
let blockedSites: unknown[] = [];
/** The real Inertia useHttp over a fake transport: what the server answers is all that is faked. */
function fakeTransport(): Promise<HttpResponse> {
    return Promise.resolve({
        status: 200,
        data: JSON.stringify({ sites: blockedSites }),
        headers: {},
    });
}

beforeEach(() => {
    blockedSites = [];
    http.setClient({ request: fakeTransport });
    vi.setSystemTime(new Date('2026-11-07T12:00:00Z'));
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
    HTMLElement.prototype.scrollIntoView = vi.fn();
    vi.stubGlobal(
        'URL',
        class extends URL {
            static override createObjectURL = vi.fn(() => 'blob:client-avatar');
            static override revokeObjectURL = vi.fn();
        },
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

function page(
    overrides: Partial<Heptapod.Data.Ui.ClientSettingsPageData> = {},
) {
    return (
        <TooltipProvider>
            <ClientSettings
                client={clients[0]!}
                members={[]}
                avatar_sites={[]}
                timezones={['UTC']}
                clients={[
                    { ...clients[1]!, is_internal: true, name: 'No client' },
                ]}
                site_count={3}
                archive_paused_site_count={2}
                {...overrides}
            />
        </TooltipProvider>
    );
}

it('defaults to archiving sites and preselects No client when moving them instead', async () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    render(page());
    fireEvent.click(screen.getByRole('button', { name: 'Archive client' }));
    const dialog = screen.getByRole('dialog');
    expect(
        within(dialog)
            .getByRole('radio', { name: 'Archive the sites too' })
            .getAttribute('aria-checked'),
    ).toBe('true');
    expect(within(dialog).queryByRole('combobox')).toBeNull();
    fireEvent.click(
        within(dialog).getByRole('radio', {
            name: 'Move the sites to another client',
        }),
    );
    expect(
        within(dialog).getByRole('combobox', { name: 'Client' }).textContent,
    ).toContain('No client');
    const confirm = within(dialog).getByRole('button', {
        name: 'Archive client',
    });
    await waitFor(() => expect(confirm.hasAttribute('disabled')).toBe(false));
    fireEvent.click(confirm);
    expect(post).toHaveBeenCalledWith(
        `/clients/${clients[0]!.id}/archive`,
        { site_action: 'move', client_id: String(clients[1]!.id) },
        expect.any(Object),
    );
    expect(
        within(dialog)
            .getByRole('button', { name: 'Archive client' })
            .hasAttribute('disabled'),
    ).toBe(true);
});

it('names the sites that cannot move and blocks only the move until they can', async () => {
    blockedSites = [
        { id: 7, name: 'API', reason: 'open_incident' },
        { id: 8, name: 'Shop', reason: 'alerts_pending' },
        { id: 9, name: 'Blog', reason: 'alerts_pending' },
    ];
    render(page());
    fireEvent.click(screen.getByRole('button', { name: 'Archive client' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(
        within(dialog).getByRole('radio', {
            name: 'Move the sites to another client',
        }),
    );
    expect(
        await within(dialog).findByText(
            'API cannot move until its open incident is resolved.',
        ),
    ).toBeTruthy();
    expect(
        within(dialog).getByText(
            'Shop and Blog cannot move yet; their alerts are still being sent.',
        ),
    ).toBeTruthy();
    const confirm = within(dialog).getByRole('button', {
        name: 'Archive client',
    });
    expect(confirm.hasAttribute('disabled')).toBe(true);
    fireEvent.click(
        within(dialog).getByRole('radio', { name: 'Archive the sites too' }),
    );
    expect(confirm.hasAttribute('disabled')).toBe(false);
});

it('defaults to resuming recorded sites and submits the opt-out on restore', () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    render(
        page({
            client: { ...clients[0]!, archived_at: '2026-11-06T12:00:00Z' },
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Restore client' }));
    const dialog = screen.getByRole('dialog');
    const checkbox = within(dialog).getByRole('checkbox', {
        name: 'Resume the 2 sites that archiving paused',
    });
    expect(checkbox.getAttribute('aria-checked')).toBe('true');
    fireEvent.click(checkbox);
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Restore client' }),
    );
    expect(post).toHaveBeenCalledWith(
        `/clients/${clients[0]!.id}/restore`,
        { resume_sites: false },
        expect.any(Object),
    );
});

it('explains an empty recorded set without offering to resume unrecorded sites', () => {
    render(
        page({
            client: { ...clients[0]!, archived_at: '2026-10-01T12:00:00Z' },
            archive_paused_site_count: 0,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Restore client' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).queryByRole('checkbox')).toBeNull();
    expect(
        within(dialog).getByText(
            'Sites stay paused; resume them on each site.',
        ),
    ).toBeTruthy();
});

it('does not suggest resuming sites when restoring an empty client', () => {
    render(
        page({
            client: { ...clients[0]!, archived_at: '2026-10-01T12:00:00Z' },
            site_count: 0,
            archive_paused_site_count: 0,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Restore client' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).queryByRole('checkbox')).toBeNull();
    expect(
        within(dialog).queryByText(
            'Sites stay paused; resume them on each site.',
        ),
    ).toBeNull();
});

it('uses a plain archive confirmation for an empty client', () => {
    render(page({ site_count: 0 }));
    fireEvent.click(screen.getByRole('button', { name: 'Archive client' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).queryByRole('radiogroup')).toBeNull();
    expect(
        within(dialog).getByRole('button', { name: 'Archive client' }),
    ).toBeTruthy();
});

it('keeps the No client name read-only while other settings still save', () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    render(
        page({
            client: { ...clients[0]!, is_internal: true, name: 'No client' },
        }),
    );
    const name = screen.getByRole('textbox', {
        name: 'Client name',
    }) as HTMLInputElement;
    expect(name.readOnly).toBe(true);
    expect(name.value).toBe('No client');
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact name' }), {
        target: { value: 'Team contact' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(post).toHaveBeenCalledWith(
        `/clients/${clients[0]!.id}`,
        expect.not.objectContaining({ name: expect.anything() }),
        expect.any(Object),
    );
    expect(post.mock.calls[0]![1]).toEqual(
        expect.objectContaining({
            contact_name: 'Team contact',
            _method: 'patch',
        }),
    );
});

function dropAvatar() {
    const file = new File(['avatar'], 'logo.png', { type: 'image/png' });
    fireEvent.drop(
        screen.getByRole('button', {
            name: 'Drop an image, click to choose, or paste',
        }),
        {
            dataTransfer: { files: [file] },
        },
    );
    return file;
}

it('keeps a dropped avatar local until the docked Save submits it', () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
    render(page());

    const file = dropAvatar();

    expect(screen.getByRole('img', { name: 'Avatar preview' })).toBeTruthy();
    expect(post).not.toHaveBeenCalled();
    expect(patch).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(post).toHaveBeenCalledWith(
        `/clients/${clients[0]!.id}`,
        expect.objectContaining({ logo: file, _method: 'patch' }),
        expect.any(Object),
    );
    expect(post.mock.calls[0]![1]).not.toHaveProperty('avatar');
});

it('discards the avatar draft and its preview without uploading it', () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    render(page());
    dropAvatar();

    fireEvent.click(screen.getByRole('button', { name: 'Discard' }));

    expect(screen.queryByRole('img', { name: 'Avatar preview' })).toBeNull();
    expect(
        screen.getByRole('button', { name: 'Save' }).hasAttribute('disabled'),
    ).toBe(true);
    expect(screen.getByText('No unsaved changes')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();
});

it('leaves the form clean after successfully saving the avatar and other fields', async () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    render(page());
    dropAvatar();
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact name' }), {
        target: { value: 'Saved contact' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    const visit = post.mock.calls[0]![2]!;

    await act(async () => {
        await Promise.resolve(
            visit.onSuccess?.(
                {} as Parameters<NonNullable<typeof visit.onSuccess>>[0],
            ),
        );
        visit.onFinish?.(
            {} as Parameters<NonNullable<typeof visit.onFinish>>[0],
        );
    });

    await waitFor(() =>
        expect(
            screen
                .getByRole('button', { name: 'Save' })
                .hasAttribute('disabled'),
        ).toBe(true),
    );
    expect(
        screen
            .getByRole('button', { name: 'Discard' })
            .hasAttribute('disabled'),
    ).toBe(true);
    expect(screen.queryByRole('img', { name: 'Avatar preview' })).toBeNull();
    expect(screen.getByText('No unsaved changes')).toBeTruthy();
    expect(
        (
            screen.getByRole('textbox', {
                name: 'Contact name',
            }) as HTMLInputElement
        ).value,
    ).toBe('Saved contact');
});

it('clears the avatar draft when the saved site icon is chosen again', () => {
    render(
        page({
            client: {
                ...clients[0]!,
                avatar_site_id: 101,
                avatar: {
                    url: '/sites/101/icon/hash',
                    backing: 'none',
                    source: 'chosen-site',
                    site_id: 101,
                    site_host: 'saved.example.test',
                },
            },
            avatar_sites: [
                { id: 101, name: 'Saved site', host: 'saved.example.test' },
                { id: 102, name: 'Another site', host: 'another.example.test' },
            ],
        }),
    );
    fireEvent.click(screen.getByRole('combobox', { name: 'Use icon from…' }));
    fireEvent.click(screen.getByRole('option', { name: /Another site/ }));
    expect(
        screen.getByRole('button', { name: 'Save' }).hasAttribute('disabled'),
    ).toBe(false);

    fireEvent.click(screen.getByRole('combobox', { name: 'Use icon from…' }));
    fireEvent.click(screen.getByRole('option', { name: /Saved site/ }));

    expect(
        screen.getByRole('button', { name: 'Save' }).hasAttribute('disabled'),
    ).toBe(true);
    expect(screen.getByText('No unsaved changes')).toBeTruthy();
});
