import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ProductCheckDetail } from './product-check-detail';
import { SiteCheckRows } from './site-overview-content';
import { checkTabPageFixture, sites } from '@/fixtures/design';
import type { RequestedRun } from '@/hooks/use-requested-run';

vi.mock('react-i18next', () => {
    const t = (key: string, values: Record<string, string> = {}) =>
        key.replace(/{{(\w+)}}/g, (_, name: string) => values[name] ?? name);
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { timezone: 'UTC', auth: { canManageChannels: true } },
    }),
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/components/check-configuration', () => ({
    CheckConfiguration: () => null,
}));
vi.mock('@/components/check-history', () => ({ CheckHistory: () => null }));
vi.mock('@/components/check-evidence', () => ({ CheckEvidence: () => null }));

const own: RequestedRun = {
    state: { phase: 'idle' },
    finished: null,
    request: vi.fn(),
    accept: vi.fn(),
    abandon: vi.fn(),
    consume: vi.fn(),
};

function page(pending: boolean) {
    const site = sites[0];
    if (!site) throw new Error('Site fixture missing');
    const dns = site.checks.find((check) => check.key === 'dns');
    if (!dns) throw new Error('DNS fixture missing');
    return checkTabPageFixture(site, {
        ...dns,
        result: 'failed',
        state: pending ? 'pending' : 'failed',
        pending_recheck: pending,
        summary: 'Old failed DNS result',
        checked_at: '2026-10-22T12:00:00Z',
        presentation: {
            parts: [
                {
                    key: 'Old failed DNS result',
                    params: {},
                    labels: {},
                    shortKey: null,
                    trusted: [],
                    lists: {},
                    untrusted: {},
                },
            ],
            changes: [
                {
                    key: 'Old change chip',
                    params: {},
                    labels: {},
                    shortKey: null,
                    trusted: [],
                    lists: {},
                    untrusted: {},
                },
            ],
            details: [],
            explanation: null,
            history_label: null,
            names_check: false,
            headline_limit: 2,
            headline_count: 1,
            more_reasons: 0,
        },
    });
}

afterEach(cleanup);

it('shows the missing capability instead of awaiting a result in an unavailable overview check row', () => {
    render(
        <SiteCheckRows
            siteId={1}
            checks={[
                {
                    ...page(false).check,
                    result: 'pending',
                    state: 'unavailable',
                    summary: null,
                    presentation: null,
                    unavailable_reason: 'lighthouse.browser_unavailable',
                },
            ]}
        />,
        { wrapper: TooltipProvider },
    );
    expect(screen.getByText('lighthouse.browser_unavailable')).not.toBeNull();
    expect(screen.queryByText('Awaiting first check result')).toBeNull();
});

it('shows the projected pending state in the check header with a muted previous result and no old change chip', () => {
    render(<ProductCheckDetail page={page(true)} requestedRun={own} />, {
        wrapper: TooltipProvider,
    });
    expect(
        screen.getByRole('heading', { name: 'Checking the accepted records…' }),
    ).not.toBeNull();
    expect(screen.getByText('Pending')).not.toBeNull();
    expect(screen.queryByText('Failed', { exact: true })).toBeNull();
    expect(screen.getByText(/Last result: Failed/)).not.toBeNull();
    expect(screen.queryByText('Old change chip')).toBeNull();
});

it('keeps the stored header and change chip when no accepted recheck is pending', () => {
    render(<ProductCheckDetail page={page(false)} requestedRun={own} />, {
        wrapper: TooltipProvider,
    });
    expect(
        screen.getByRole('heading', { name: 'Old failed DNS result.' }),
    ).not.toBeNull();
    expect(screen.getByText('Failed', { exact: true })).not.toBeNull();
    expect(screen.getByText('Old change chip')).not.toBeNull();
    expect(screen.queryByText(/Last result: Failed/)).toBeNull();
});

it('uses the same projected pending state and headline in the overview check row', () => {
    render(<SiteCheckRows siteId={1} checks={[page(true).check]} />, {
        wrapper: TooltipProvider,
    });
    expect(screen.getByText('Checking the accepted records…')).not.toBeNull();
    expect(screen.getByRole('img', { name: 'Pending' })).not.toBeNull();
    expect(screen.queryByText('Old change chip')).toBeNull();
});
