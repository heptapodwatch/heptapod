import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import type { ComponentProps } from 'react';
import ClientsIndex from '@/pages/clients/index';
import { clients } from '@/fixtures/design';
import i18n from '@/lib/i18n';

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ url: '/clients', props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

function page(
    counts: Partial<Heptapod.Data.Ui.DashboardTilesData>,
): Heptapod.Data.Ui.ClientsIndexPageData {
    const client = clients[0];
    if (!client) throw new Error('Client fixture missing');
    return {
        clients: [
            {
                client,
                summary: {
                    sites: 0,
                    down: 0,
                    check_errors: 0,
                    warning: 0,
                    pending: 0,
                    paused: 0,
                    open_incidents: 0,
                    uptime: null,
                    certificates_expiring: 0,
                    cron_jobs_failing: 0,
                    ok: 0,
                    failed: 0,
                    maintenance: 0,
                    disabled: 0,
                    unavailable: 0,
                    certificates_expiring_30_days: null,
                    domains_expiring_30_days: null,
                    scheduled_tasks: null,
                    scheduled_tasks_overdue_pings: null,
                    ...counts,
                },
            },
        ],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 1,
            previous_page_url: null,
            next_page_url: null,
        },
        search: null,
        state: 'all',
        can_manage: false,
    };
}

afterEach(cleanup);

it.each(['en', 'de'])(
    'omits zero OK counts beside nonzero states in desktop and mobile client summaries in %s',
    async (locale) => {
        await i18n.changeLanguage(locale);
        render(
            <I18nextProvider i18n={i18n}>
                <ClientsIndex {...page({ sites: 2, warning: 2 })} />
            </I18nextProvider>,
        );

        expect(screen.queryAllByText(/^(0 OK|OK: 0)$/)).toHaveLength(0);
        expect(
            screen.getAllByText(locale === 'de' ? 'Warnung: 2' : 'Warning: 2'),
        ).toHaveLength(2);
    },
);

it('uses the neutral placeholder when every status count is zero', async () => {
    await i18n.changeLanguage('en');
    render(
        <I18nextProvider i18n={i18n}>
            <ClientsIndex {...page({})} />
        </I18nextProvider>,
    );

    expect(screen.getAllByRole('cell')[2]?.textContent).toBe('—');
    expect(screen.queryAllByText(/^(0 OK|OK: 0)$/)).toHaveLength(0);
});

it('keeps a nonzero OK count visible', async () => {
    await i18n.changeLanguage('en');
    render(
        <I18nextProvider i18n={i18n}>
            <ClientsIndex {...page({ sites: 3, ok: 3 })} />
        </I18nextProvider>,
    );

    expect(screen.getAllByText('OK: 3')).toHaveLength(2);
});

function emptyPage(
    changes: Partial<Heptapod.Data.Ui.ClientsIndexPageData>,
): Heptapod.Data.Ui.ClientsIndexPageData {
    return {
        ...page({}),
        clients: [],
        pagination: { ...page({}).pagination, total: 0 },
        ...changes,
    };
}

it('shows first-run copy and New client on an empty instance, never Clear filters', async () => {
    await i18n.changeLanguage('en');
    render(
        <I18nextProvider i18n={i18n}>
            <ClientsIndex {...emptyPage({ can_manage: true })} />
        </I18nextProvider>,
    );

    expect(screen.getByText('No clients yet')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Clear filters' })).toBeNull();
    // The header action and the empty-state action both create a client.
    expect(screen.getAllByRole('link', { name: 'New client' })).toHaveLength(2);
});

it.each([
    ['a search', { search: 'acme' }],
    ['the attention segment', { state: 'attention' }],
])('offers Clear filters when %s hides every client', async (_, changes) => {
    await i18n.changeLanguage('en');
    render(
        <I18nextProvider i18n={i18n}>
            <ClientsIndex {...emptyPage({ can_manage: true, ...changes })} />
        </I18nextProvider>,
    );

    expect(screen.getByText('No matching clients')).toBeTruthy();
    // The toolbar's one Clear filters; the empty state only points to it.
    expect(
        screen.getAllByRole('button', { name: 'Clear filters' }),
    ).toHaveLength(1);
    expect(screen.getAllByRole('link', { name: 'New client' })).toHaveLength(1);
});
