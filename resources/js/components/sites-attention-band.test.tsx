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
            clientCount={0}
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
    list_scores: [],
    identity: {
        id,
        name: `Site ${id}`,
        url: `https://site-${id}.example`,
        client_id: 1,
        state: 'warning',
        icon_url: null,
        icon_backing: 'none',
        deleting: false,
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
            clientCount={0}
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
    'calls the portfolio OK when the only non-OK sites are %s; the meta line names them',
    (state) => {
        causes = [];
        const { container } = render(
            <SitesAttentionBand
                tiles={{ ...tiles, sites: 64, ok: 63, [state]: 1 }}
                needsAttention={[]}
                needsAttentionTotal={0}
                clients={[]}
                onShowAll={() => {}}
                clientCount={0}
            />,
            { wrapper: TooltipProvider },
        );
        expect(
            container.querySelector('[data-sites-headline]')?.textContent,
        ).toBe('{{count}} of {{total}} sites are OK.');
        expect(breakdown(container)).toEqual([state]);
    },
);

/** The states drawn in the header's meta line, in order. */
const breakdown = (container: HTMLElement) =>
    [
        ...container.querySelectorAll<HTMLElement>(
            '[data-page-header] .status-ring',
        ),
    ].map((dot) => dot.dataset.state);

const bandSite = (
    id: number,
    state: Heptapod.Enums.SiteStatus,
): Heptapod.Data.Ui.SiteRowData => {
    const site = attentionSite(id);
    return { ...site, state, identity: { ...site.identity, state } };
};

/** The header's meta line shows the shape of every non-OK state, so no site is silent (heptapod-ui-rules Sites header). */
it.each([
    {
        name: 'a Down site in the band',
        tileState: { down: 1, ok: 3 },
        band: ['down'],
        breakdown: ['down'],
    },
    {
        name: 'a Failed and a paused site',
        tileState: { failed: 1, paused: 1, ok: 2 },
        band: ['failed'],
        breakdown: ['failed', 'paused'],
    },
    {
        name: 'a Warning site in the band',
        tileState: { warning: 1, ok: 3 },
        band: ['warning'],
        breakdown: ['warning'],
    },
    {
        name: 'a Check error site in the band',
        tileState: { check_errors: 1, ok: 3 },
        band: ['check-error'],
        breakdown: ['check-error'],
    },
    {
        name: 'only an unavailable site besides OK sites',
        tileState: { unavailable: 1, ok: 3 },
        band: [],
        breakdown: ['unavailable'],
    },
    {
        name: 'all sites OK',
        tileState: { ok: 4 },
        band: [],
        breakdown: [],
    },
    {
        name: 'stale monitoring (dispatcher down), which states only that',
        tileState: { warning: 1, ok: 3 },
        band: [],
        health: { status: 'down', last_result_at: null, dispatcher_at: null },
        breakdown: [],
    },
] as const)('shows the state breakdown for $name', (row) => {
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
            clientCount={1}
        />,
        { wrapper: TooltipProvider },
    );
    expect(breakdown(container)).toEqual([...row.breakdown]);
});
