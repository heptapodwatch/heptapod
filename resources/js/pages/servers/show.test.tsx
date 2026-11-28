import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import ServerShow from '@/pages/servers/show';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ flash: {}, props: { timezone: 'UTC' } }),
    usePoll: vi.fn(),
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
afterEach(cleanup);

it('only enables Save for a changed server settings draft and lets the server validate it', async () => {
    const defaults = {
        disk_percent: 90,
        disk_minutes: 15,
        mounts: [],
        memory_percent: 90,
        memory_minutes: 10,
        load_per_core: 2,
        load_minutes: 15,
        no_report_minutes: 2,
    };
    render(
        <ServerShow
            server={{
                id: 1,
                name: 'Shop server',
                state: 'awaiting',
                last_report_at: null,
                agent_version: null,
                values: null,
                thresholds: defaults,
                ignored_mounts: [],
                site_count: 0,
                hostname: null,
                agent_os: null,
                agent_arch: null,
                agent_distro: null,
                agent_kernel: null,
                ips: [],
                issues: [],
                snoozed_until: null,
            }}
            defaults={defaults}
            history={{ step_seconds: 300, points: [] }}
            install={{
                endpoint: '/agent/v1/report',
                installed_version: '',
                version: null,
                release_url: null,
            }}
            linked_sites={[]}
            linked_sites_pagination={{
                current_page: 1,
                per_page: 25,
                total: 0,
                last_page: 1,
                next_page_url: null,
                previous_page_url: null,
                page_name: 'page',
            }}
        />,
    );
    fireEvent.keyDown(screen.getByRole('button', { name: 'More actions' }), {
        key: 'Enter',
    });
    fireEvent.click(
        await screen.findByRole('menuitem', {
            name: 'servers.settings.action',
        }),
    );

    const controls = within(
        await screen.findByRole('dialog', { name: 'servers.settings.title' }),
    );
    const save = controls.getByRole<HTMLButtonElement>('button', {
        name: 'Save',
    });
    const name = controls.getByRole('textbox', { name: 'Name' });
    expect(save.disabled).toBe(true);
    fireEvent.change(name, { target: { value: 'Renamed server' } });
    expect(save.disabled).toBe(false);
    fireEvent.change(name, { target: { value: '' } });
    expect(save.disabled).toBe(false);
    fireEvent.change(name, { target: { value: 'Shop server' } });
    expect(save.disabled).toBe(true);
});
