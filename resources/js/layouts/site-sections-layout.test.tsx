import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { clients, sites } from '@/fixtures/design';
import i18n from '@/lib/i18n';
import { SiteSectionsLayout } from './site-sections-layout';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    router: { post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

function Wrapper({ children }: { children: ReactNode }) {
    return (
        <I18nextProvider i18n={i18n}>
            <TooltipProvider>
                <SidebarProvider>{children}</SidebarProvider>
            </TooltipProvider>
        </I18nextProvider>
    );
}

afterEach(cleanup);

it('groups checks by attention with short states, keeps their places on live updates and opens the drawer below lg', async () => {
    await i18n.changeLanguage('en');
    const site = sites[0]!;
    const base = site.checks[0]!;
    const checks: Heptapod.Data.Ui.CheckStripData[] = [
        {
            ...base,
            id: 1,
            key: 'uptime',
            label_key: 'checks.uptime.label',
            enabled: true,
            state: 'ok',
            result: 'succeeded',
            summary: 'uptime.succeeded',
            summary_params: {},
            presentation: null,
        },
        {
            ...base,
            id: 2,
            key: 'certificate_health',
            label_key: 'checks.certificate_health.label',
            enabled: true,
            state: 'warning',
            result: 'warning',
            summary: 'certificate_health.expires_soon',
            summary_params: { days: 9 },
            presentation: null,
        },
        {
            ...base,
            id: 3,
            key: 'broken_links',
            label_key: 'checks.broken_links.label',
            enabled: true,
            state: 'failed',
            result: 'failed',
            summary: 'broken_links.failed',
            summary_params: {},
            presentation: {
                parts: [
                    {
                        key: 'broken_links.presentation.internal',
                        params: { count: 3 },
                        labels: {},
                        shortKey: null,
                        trusted: [],
                        lists: {},
                        untrusted: {},
                    },
                    {
                        key: 'broken_links.presentation.external',
                        params: { count: 12 },
                        labels: {},
                        shortKey: null,
                        trusted: [],
                        lists: {},
                        untrusted: {},
                    },
                ],
                changes: [],
                details: [],
                explanation: null,
                history_label: null,
                names_check: false,
                headline_limit: 2,
                headline_count: 2,
                more_reasons: 0,
            },
        },
    ];

    const layout = (rows: Heptapod.Data.Ui.CheckStripData[]) => (
        <SiteSectionsLayout
            site={{ ...site, checks: rows }}
            client={clients[0]!}
            checks={rows}
            value="3"
            canOpenSettings
            maintenance={null}
        >
            <div>Check body</div>
        </SiteSectionsLayout>
    );
    const { rerender } = render(layout(checks), { wrapper: Wrapper });

    const railLinks = () =>
        within(screen.getAllByRole('navigation', { name: 'Site sections' })[0]!)
            .getAllByRole('link')
            .map((link) => link.textContent);
    // Overview, Needs attention (Broken links failed before Certificate warning), Other checks (Uptime), Settings.
    expect(railLinks()).toEqual([
        'Overview',
        'Broken links15 broken',
        'CertificateExpires in 9 days',
        'UptimeUp',
        'Settings',
    ]);
    expect(
        screen
            .getByRole('link', { name: /Broken links/ })
            .getAttribute('aria-current'),
    ).toBe('page');

    // A live update changes the state in place; the check keeps its group until the next visit.
    rerender(
        layout(
            checks.map((check) =>
                check.id === 3
                    ? {
                          ...check,
                          state: 'ok' as const,
                          result: 'succeeded' as const,
                          presentation: null,
                      }
                    : check,
            ),
        ),
    );
    expect(railLinks()).toEqual([
        'Overview',
        'Broken linksOK',
        'CertificateExpires in 9 days',
        'UptimeUp',
        'Settings',
    ]);

    // Below lg the switcher opens the same navigation in a drawer.
    fireEvent.click(
        screen.getByRole('button', { name: /Switch site section/ }),
    );
    expect(
        within(screen.getByRole('dialog')).getByRole('navigation', {
            name: 'Site sections',
        }),
    ).not.toBeNull();
});
