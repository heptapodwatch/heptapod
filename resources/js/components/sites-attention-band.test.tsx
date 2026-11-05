import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import { SitesAttentionBand } from './sites-attention-band';

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
let causes: unknown;
const okHealth = {
    status: 'ok',
    active_checks: 1,
    notifications_covered: true,
};
let health: Record<string, unknown> = okHealth;
vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    usePage: () => ({
        props: {
            timezone: 'UTC',
            auth: { canManageChannels: true },
            overallHealth: { ...health, causes },
        },
    }),
}));
const tiles: Heptapod.Data.Ui.DashboardTilesData = {
    sites: 1,
    ok: 1,
    down: 0,
    check_errors: 0,
    warning: 0,
    pending: 0,
    paused: 0,
    open_incidents: 0,
    uptime: 100,
    certificates_expiring: 0,
    cron_jobs_failing: 0,
    failed: 0,
    maintenance: 0,
    disabled: 0,
    unavailable: 0,
    certificates_expiring_30_days: null,
    domains_expiring_30_days: null,
    scheduled_tasks: null,
    scheduled_tasks_overdue_pings: null,
};
beforeEach(() => {
    vi.stubGlobal('matchMedia', () => ({
        matches: true,
        addEventListener() {},
        removeEventListener() {},
    }));
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    health = okHealth;
});

it('keeps monitoring-health causes out of the band; PageHeader notices own them', () => {
    causes = [
        {
            title_key: 'Email delivery is not configured',
            action_key: 'Configure email delivery',
            href: '/settings/system',
        },
    ];
    render(
        <SitesAttentionBand
            tiles={tiles}
            needsAttention={[]}
            needsAttentionTotal={0}
            clients={[]}
            onShowAll={() => {}}
        />,
        { wrapper: TooltipProvider },
    );
    expect(screen.queryByText('Email delivery is not configured')).toBeNull();
});

const attentionSite = (id: number): Heptapod.Data.Ui.SiteRowData => ({
    id,
    name: `Site ${id}`,
    url: `https://site-${id}.example`,
    client_id: 1,
    tags: [],
    state: 'warning',
    uptime: null,
    response: [],
    checks: [],
    checked_at: null,
    identity: {
        id,
        name: `Site ${id}`,
        url: `https://site-${id}.example`,
        client_id: 1,
        state: 'warning',
        icon_url: null,
        icon_backing: 'none',
    },
});

it('links overflow as "Show all N" for every site needing attention, never "+N more"', () => {
    causes = [];
    const onShowAll = vi.fn();
    render(
        <SitesAttentionBand
            tiles={{ ...tiles, sites: 7, ok: 0, warning: 7 }}
            needsAttention={[1, 2, 3, 4, 5].map(attentionSite)}
            needsAttentionTotal={7}
            clients={[]}
            onShowAll={onShowAll}
        />,
        { wrapper: TooltipProvider },
    );
    const links = screen.getAllByRole('button', {
        name: 'sites.attention_show_all',
    });
    // One link per breakpoint (desktop 5 rows, mobile 3 rows); both name the full set.
    expect(links).toHaveLength(2);
    links[0]!.click();
    expect(onShowAll).toHaveBeenCalledOnce();
    expect(document.body.textContent).not.toMatch(/\+\s*\d+\s*more/);
});

it.each(['maintenance', 'paused', 'pending', 'disabled'] as const)(
    'calls the portfolio OK when the only non-OK sites are %s; the detail line names them',
    (state) => {
        causes = [];
        const { container } = render(
            <SitesAttentionBand
                tiles={{ ...tiles, sites: 64, ok: 63, [state]: 1 }}
                needsAttention={[]}
                needsAttentionTotal={0}
                clients={[]}
                onShowAll={() => {}}
            />,
            { wrapper: TooltipProvider },
        );
        const badge = container.querySelector('h2 [data-state]');
        expect(badge?.getAttribute('data-state')).toBe('ok');
        expect(
            screen.getByText('{{count}} of {{total}} sites are OK.'),
        ).toBeTruthy();
        expect(screen.getByText(`sites.not_ok_${state}`)).toBeTruthy();
    },
);

const bandSite = (
    id: number,
    state: Heptapod.Enums.SiteStatus,
): Heptapod.Data.Ui.SiteRowData => {
    const site = attentionSite(id);
    return { ...site, state, identity: { ...site.identity, state } };
};

/** Headline chip for every portfolio state; the detail line keeps non-band states (heptapod-ui-rules Sites headline). */
it.each([
    {
        name: 'a Down site in the band',
        tileState: { down: 1, ok: 3 },
        band: ['down'],
        chip: 'down',
    },
    {
        name: 'a Failed site in the band',
        tileState: { failed: 1, ok: 3 },
        band: ['failed'],
        chip: 'failed',
    },
    {
        name: 'a Warning site in the band',
        tileState: { warning: 1, ok: 3 },
        band: ['warning'],
        chip: 'warning',
    },
    {
        name: 'a Check error site in the band',
        tileState: { check_errors: 1, ok: 3 },
        band: ['check-error'],
        chip: 'check-error',
    },
    {
        name: 'all OK but alerts not covered',
        tileState: { ok: 4 },
        band: [],
        health: { notifications_covered: false },
        chip: 'warning',
    },
    {
        name: 'monitoring degraded',
        tileState: { ok: 4 },
        band: [],
        health: { status: 'warn' },
        chip: 'warning',
    },
    {
        name: 'stale monitoring (dispatcher down)',
        tileState: { ok: 4 },
        band: [],
        health: { status: 'down', last_result_at: null, dispatcher_at: null },
        chip: 'check-error',
    },
    {
        name: 'no active checks',
        tileState: { paused: 4, ok: 0 },
        band: [],
        health: { active_checks: 0 },
        chip: 'paused',
    },
    {
        name: 'only an unavailable site besides OK sites',
        tileState: { unavailable: 1, ok: 3 },
        band: [],
        chip: 'ok',
        detail: 'sites.not_ok_unavailable',
    },
    {
        name: 'all sites OK',
        tileState: { ok: 4 },
        band: [],
        chip: 'ok',
    },
] as const)('shows the $chip chip for $name', (row) => {
    causes = [];
    health = { ...okHealth, ...('health' in row ? row.health : {}) };
    const { container } = render(
        <SitesAttentionBand
            tiles={{ ...tiles, sites: 4, ...row.tileState }}
            needsAttention={row.band.map((state, index) =>
                bandSite(index + 1, state),
            )}
            needsAttentionTotal={row.band.length}
            clients={[]}
            onShowAll={() => {}}
        />,
        { wrapper: TooltipProvider },
    );
    expect(
        container.querySelector('h2 [data-state]')?.getAttribute('data-state'),
    ).toBe(row.chip);
    if ('detail' in row && row.detail) {
        expect(screen.getByText(row.detail)).toBeTruthy();
    }
});
