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
    return {
        ...original,
        Head: () => null,
        usePage: () => ({
            props: {
                instanceTimezone: 'Europe/Berlin',
                supportedLocales: [{ code: 'en', name: 'English' }],
            },
        }),
    };
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
    maintenance: null,
    cron_definitions: null,
});
const sections: Heptapod.Enums.ImportSection[] = [
    'sites',
    'maintenance',
    'cron',
];
const plan = {
    plan_id: '0b5f2a8e-7c1d-4e6f-9a3b-2d4c6e8f0a1b',
    dry_run: true,
    sections,
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
            sections={sections}
            plan_page_size={50}
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
    fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
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

const showPlan = async (planPageSize = 50) => {
    render(
        <ImportOhDear
            clients={[internal, ...clients]}
            timezones={['UTC', 'Europe/Berlin']}
            sections={sections}
            plan_page_size={planPageSize}
        />,
    );
    fireEvent.change(screen.getByLabelText('Oh Dear API token'), {
        target: { value: 'token' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Show plan' }));
    await screen.findByRole('combobox', { name: 'Client for Agency' });
};
const choose = (picker: string, option: string) => {
    fireEvent.click(screen.getByRole('combobox', { name: picker }));
    fireEvent.click(screen.getByRole('option', { name: option }));
};

it('resolves a tag conflict with a client created in the conflict picker', async () => {
    vi.spyOn(router, 'visit').mockImplementation(() => {});
    const conflicting = { ...plan, rows: [row('shop', ['retail', 'sale'])] };
    const request = vi
        .spyOn(http.getClient(), 'request')
        .mockImplementation(async ({ url }) => {
            if (url.endsWith('/plan')) return json(conflicting);
            if (url === '/clients') return json(created, 201);
            return json({ url: '/settings/import/ohdear/runs/run-1' });
        });
    await showPlan();
    choose('Client for retail', clients[0]!.name);
    choose('Client for sale', clients[1]!.name);
    const conflict = screen.getByRole('combobox', { name: 'Client for shop' });
    expect(conflict.getAttribute('aria-invalid')).toBe('true');
    expect(
        screen.getByText(
            `Tags of this site point to ${clients[0]!.name} and ${clients[1]!.name}. Choose one.`,
        ),
    ).not.toBeNull();

    fireEvent.click(conflict);
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        { target: { value: 'Studio Nord' } },
    );
    fireEvent.click(
        screen.getByRole('option', { name: 'clients.create_inline' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const resolved = screen.getByRole('combobox', { name: 'Client for shop' });
    expect(resolved.getAttribute('aria-invalid')).toBeNull();
    expect(within(resolved).getByText('Studio Nord')).not.toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Start import' }));
    await waitFor(() =>
        expect(
            JSON.parse(request.mock.calls.at(-1)![0].data as string).overrides,
        ).toEqual({ shop: { client_id: 987 } }),
    );
});

it('locks every client picker and Start import while a new plan loads, and says so on Show plan', async () => {
    let planCalls = 0;
    vi.spyOn(http.getClient(), 'request').mockImplementation(({ url }) =>
        url.endsWith('/plan') && ++planCalls > 1
            ? new Promise(() => {})
            : Promise.resolve(json(plan)),
    );
    await showPlan();
    const pickers = () =>
        screen
            .getAllByRole('combobox')
            .filter((box) =>
                box.getAttribute('aria-label')?.startsWith('Client for'),
            );
    expect(pickers().every((box) => !box.hasAttribute('disabled'))).toBe(true);

    fireEvent.change(screen.getByLabelText('Oh Dear API token'), {
        target: { value: 'token' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Show plan' }));
    await waitFor(() =>
        expect(pickers().every((box) => box.hasAttribute('disabled'))).toBe(
            true,
        ),
    );
    expect(
        screen
            .getByRole('button', { name: 'Start import' })
            .hasAttribute('disabled'),
    ).toBe(true);
    expect(
        screen
            .getByRole('button', { name: 'Loading plan…' })
            .hasAttribute('disabled'),
    ).toBe(true);
});

it('pages large import plans with ListFooter, preserves overrides across pages and remembers page size', async () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    const largePlan = {
        ...plan,
        rows: Array.from({ length: 12 }, (_, index) =>
            row(`site-${index + 1}`, []),
        ),
    };
    const request = vi
        .spyOn(http.getClient(), 'request')
        .mockImplementation(async ({ url }) => {
            if (url.endsWith('/plan')) return json(largePlan);
            return json({ url: '/settings/import/ohdear/runs/run-1' });
        });
    await showPlan(10);

    expect(screen.getByText('site-1')).not.toBeNull();
    expect(screen.queryByText('site-11')).toBeNull();
    choose('Client for site-1', clients[0]!.name);

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(screen.queryByText('site-1')).toBeNull();
    expect(screen.getByText('site-11')).not.toBeNull();
    expect(screen.getByText('site-12')).not.toBeNull();

    // Changing Rows remembers the choice in list_page_sizes and resets to page 1.
    fireEvent.click(screen.getByRole('radio', { name: '20' }));
    expect(screen.getByText('site-1')).not.toBeNull();
    expect(screen.getByText('site-12')).not.toBeNull();
    const cookieValue = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('list_page_sizes='))
        ?.slice('list_page_sizes='.length);
    expect(JSON.parse(decodeURIComponent(cookieValue ?? '{}'))).toMatchObject({
        import_plan_rows: 20,
    });

    // Submitting sends every row count and preserves the override chosen on page 1.
    fireEvent.click(screen.getByRole('button', { name: 'Start import' }));
    await waitFor(() =>
        expect(visit).toHaveBeenCalledWith(
            '/settings/import/ohdear/runs/run-1',
        ),
    );
    const start = request.mock.calls.at(-1)![0];
    expect(JSON.parse(start.data as string)).toMatchObject({
        expected: 12,
        overrides: { 'site-1': { client_id: clients[0]!.id } },
    });
});
