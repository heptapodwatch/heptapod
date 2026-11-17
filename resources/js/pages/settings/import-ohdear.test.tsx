import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { http, router } from '@inertiajs/react';
import { clients } from '@/fixtures/design';
import ImportOhDear from '@/pages/settings/import-ohdear';

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();
    return { ...original, Head: () => null };
});
vi.mock('react-i18next', () => {
    const t = (key: string, params?: Record<string, unknown>) =>
        key.replace(/:(\w+)/g, (match, name: string) =>
            params && name in params ? String(params[name]) : match,
        );
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});

const internal = {
    ...clients[0]!,
    id: 10,
    name: 'No client',
    is_internal: true,
};
const row = (sourceId: string, tags: string[]) => ({
    source_id: sourceId,
    url: `https://${sourceId}.example.test`,
    label: sourceId,
    team_id: 1,
    tags,
    operation: 'create',
    checks: ['uptime'],
    disabled_checks: [],
    notices: [],
    details: '',
    site_id: null,
    client_name: null,
    inherits_alert_timing: null,
});
const plan = {
    plan_id: '0b5f2a8e-7c1d-4e6f-9a3b-2d4c6e8f0a1b',
    dry_run: true,
    teams: [{ id: 1, name: 'Agency' }],
    rows: [row('shop', ['retail']), row('blog', [])],
    remote_error: null,
};
const created = { ...clients[0]!, id: 987, name: 'Studio Nord' };
const json = (data: unknown, status = 200) => ({
    status,
    headers: { 'content-type': 'application/json' },
    data: JSON.stringify(data),
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
    Element.prototype.scrollIntoView = vi.fn();
    document.cookie = 'XSRF-TOKEN=test';
});
afterEach(async () => {
    cleanup();
    await new Promise((resolve) => setTimeout(resolve, 0));
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('creates a client from a group picker, selects it there, offers it in every picker and imports into it', async () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    const request = vi
        .spyOn(http.getClient(), 'request')
        .mockImplementation(async ({ url }) => {
            if (url.endsWith('/plan')) return json(plan);
            if (url === '/clients') return json(created, 201);
            return json({ url: '/settings/import/ohdear/runs/run-1' });
        });
    render(
        <ImportOhDear
            clients={[internal, ...clients]}
            timezones={['UTC', 'Europe/Berlin']}
        />,
    );
    fireEvent.change(screen.getByLabelText('Oh Dear API token'), {
        target: { value: 'token' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Show plan' }));
    const teamPicker = await screen.findByRole('combobox', {
        name: 'Client for Agency',
    });

    fireEvent.click(teamPicker);
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        { target: { value: 'Studio Nord' } },
    );
    fireEvent.click(
        screen.getByRole('option', { name: 'clients.create_inline' }),
    );
    expect(
        (screen.getByLabelText('Client name') as HTMLInputElement).value,
    ).toBe('Studio Nord');
    fireEvent.click(screen.getByRole('button', { name: 'Create client' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(within(teamPicker).getByText('Studio Nord')).not.toBeNull();

    fireEvent.click(
        screen.getByRole('combobox', { name: 'Client for retail' }),
    );
    expect(screen.getByRole('option', { name: 'Studio Nord' })).not.toBeNull();
    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });

    fireEvent.click(screen.getByRole('button', { name: 'Start import' }));
    await waitFor(() =>
        expect(visit).toHaveBeenCalledWith(
            '/settings/import/ohdear/runs/run-1',
        ),
    );
    const start = request.mock.calls.at(-1)![0];
    expect(start.url).toBe(
        `/settings/import/ohdear/runs/${plan.plan_id}/start`,
    );
    expect(JSON.parse(start.data as string)).toEqual({
        expected: 2,
        groups: { 'team:1': { client_id: 987 } },
        overrides: {},
    });
});
