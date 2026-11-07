import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
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

beforeEach(() => {
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
        <ClientSettings
            client={clients[0]!}
            members={[]}
            avatar_sites={[]}
            timezones={['UTC']}
            clients={[{ ...clients[1]!, is_internal: true, name: 'No client' }]}
            site_count={3}
            archive_paused_site_count={2}
            {...overrides}
        />
    );
}

it('defaults to archiving sites and preselects No client when moving them instead', () => {
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
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Archive client' }),
    );
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
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
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
    expect(patch).toHaveBeenCalledWith(
        `/clients/${clients[0]!.id}`,
        expect.not.objectContaining({ name: expect.anything() }),
        expect.any(Object),
    );
    expect(patch.mock.calls[0]![1]).toEqual(
        expect.objectContaining({ contact_name: 'Team contact' }),
    );
});
