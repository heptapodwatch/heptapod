import { cleanup, render } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import { SitesTable } from './sites-table';
import { TooltipProvider } from '@/components/ui/tooltip';
import { clientPagination } from '@/components/list-footer';
import { clients, sites } from '@/fixtures/design';
import i18n from '@/lib/i18n';
import type { CheckDto, SiteDto } from '@/types/monitoring';

vi.mock('@inertiajs/react', () => ({
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
    usePage: () => ({ props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

it('shows the worst active check, preferring an open incident then its ID, with a wrapping unbroken hostname', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-25T12:00:00Z'));
    await i18n.changeLanguage('en');
    const site = sites[0];
    const base = site?.checks[0];
    if (!site || !base) throw new Error('Site/check fixture missing');
    const hostname = `${'unbroken'.repeat(24)}.example.test`;
    const check = (
        id: number,
        state: CheckDto['state'],
        open: boolean,
        summary: string,
        overrides: Partial<CheckDto> = {},
    ): CheckDto => ({
        ...base,
        id,
        key: 'uptime',
        label_key: 'checks.uptime.label',
        state,
        enabled: true,
        pending_recheck: false,
        freshness_suppressed_by: null,
        held_after_recovery: false,
        presentation: null,
        summary,
        summary_params: {},
        open_incident_id: open ? id : null,
        open_incident_started_at: open ? '2026-10-25T11:00:00Z' : null,
        ...overrides,
    });
    const data: SiteDto = {
        ...site,
        state: 'down',
        checks: [
            check(1, 'warning', true, 'A warning'),
            check(2, 'check-error', true, 'A check error'),
            check(3, 'failed', true, 'A failed check'),
            check(4, 'down', false, 'Down without an incident'),
            check(7, 'down', true, 'Later open incident'),
            check(6, 'down', true, `Cannot reach ${hostname}`),
            check(5, 'down', true, 'Disabled check', { enabled: false }),
            check(0, 'down', true, 'Suppressed check', {
                freshness_suppressed_by: 'maintenance',
            }),
        ],
    };
    const { container } = render(
        <I18nextProvider i18n={i18n}>
            <TooltipProvider>
                <SitesTable
                    sites={[data]}
                    clients={clients}
                    empty={null}
                    count="1 site"
                    pagination={clientPagination(1, 50, 1)}
                />
            </TooltipProvider>
        </I18nextProvider>,
    );
    const summary = container.querySelector('[data-site-card-check]');
    if (!(summary instanceof HTMLElement))
        throw new Error('Mobile check summary missing');
    expect(summary.textContent).toBe(
        `Uptime · Cannot reach ${hostname} · Since 11:00`,
    );
    expect(summary.classList.contains('break-words')).toBe(true);
});

it('keeps the column gear in its own header cell when every hideable column is hidden', async () => {
    await i18n.changeLanguage('en');
    document.cookie = `sites_hidden_columns=${encodeURIComponent('client_id:0,why:0,checks:0,list_scores:0,response:0,uptime:0,checked_at:0')}; path=/`;
    const { container } = render(
        <I18nextProvider i18n={i18n}>
            <TooltipProvider>
                <SitesTable
                    sites={sites.slice(0, 3)}
                    clients={clients}
                    empty={null}
                    count="3 sites"
                    pagination={clientPagination(1, 50, 3)}
                />
            </TooltipProvider>
        </I18nextProvider>,
    );
    const headers = [...container.querySelectorAll('thead th')];
    // Status, Site and the gear's own cell remain; the gear is never inside a column the user can hide.
    expect(headers).toHaveLength(3);
    expect(
        headers.at(-1)?.querySelector('button[aria-label="Columns"]'),
    ).not.toBeNull();
    document.cookie = 'sites_hidden_columns=; path=/; max-age=0';
});
