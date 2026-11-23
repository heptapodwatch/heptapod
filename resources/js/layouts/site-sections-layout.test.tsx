import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { clients, sites } from '@/fixtures/design';
import i18n from '@/lib/i18n';
import { SiteSectionsLayout } from './site-sections-layout';

type Check = Heptapod.Data.Ui.CheckStripData;

const listeners = new Map<string, ((event: unknown) => void)[]>();
vi.mock('@inertiajs/react', () => ({
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    router: {
        on: (name: string, listener: (event: unknown) => void) => {
            listeners.set(name, [...(listeners.get(name) ?? []), listener]);
            return () =>
                listeners.set(
                    name,
                    (listeners.get(name) ?? []).filter(
                        (item) => item !== listener,
                    ),
                );
        },
    },
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

function emit(name: string, detail: unknown) {
    act(() =>
        (listeners.get(name) ?? []).forEach((listener) => listener({ detail })),
    );
}

function Wrapper({ children }: { children: ReactNode }) {
    return (
        <I18nextProvider i18n={i18n}>
            <TooltipProvider>
                <SidebarProvider>{children}</SidebarProvider>
            </TooltipProvider>
        </I18nextProvider>
    );
}

function part(key: string, params: Record<string, string | number>) {
    return {
        key,
        params,
        labels: {},
        shortKey: null,
        trusted: [],
        lists: {},
        untrusted: {},
    };
}

function presentation(
    parts: ReturnType<typeof part>[],
): Heptapod.Contracts.CheckRunPresentationData {
    return {
        parts,
        changes: [],
        details: [],
        explanation: null,
        history_label: null,
        names_check: false,
        headline_limit: 2,
        headline_count: parts.length,
        more_reasons: 0,
    };
}

const base = sites[0]!.checks[0]!;
function check(id: number, changes: Partial<Check>): Check {
    return {
        ...base,
        id,
        enabled: true,
        not_fresh: false,
        freshness_suppressed_by: null,
        snoozed_until: null,
        unavailable_reason: null,
        pending_recheck: false,
        summary_params: {},
        presentation: null,
        ...changes,
    };
}

const uptime = check(1, {
    key: 'uptime',
    label_key: 'checks.uptime.label',
    state: 'ok',
    result: 'succeeded',
});
const certificate = check(2, {
    key: 'certificate_health',
    label_key: 'checks.certificate_health.label',
    state: 'warning',
    result: 'warning',
    presentation: presentation([
        part('causes.certificate_expiry', { days: 9, count: 9 }),
    ]),
});
const brokenLinks = check(3, {
    key: 'broken_links',
    label_key: 'checks.broken_links.label',
    state: 'failed',
    result: 'failed',
    presentation: presentation([
        part('broken_links.presentation.internal', { count: 3 }),
        part('broken_links.presentation.external', { count: 12 }),
    ]),
});
const domain = check(4, {
    key: 'domain',
    label_key: 'checks.domain.label',
    state: 'warning',
    result: 'warning',
    presentation: presentation([
        part('checks.presentation.domain_expires', {
            date: '2026-12-11T20:26:12+00:00',
        }),
    ]),
});
// A count that is not a total of the issues: the rail shows the state word, not a number.
const sitemap = check(5, {
    key: 'sitemap',
    label_key: 'checks.sitemap.label',
    state: 'failed',
    result: 'failed',
    summary_params: { count: 4 },
});
const dns = check(6, {
    key: 'dns',
    label_key: 'checks.dns.label',
    state: 'ok',
    result: 'succeeded',
    freshness_suppressed_by: 'uptime_error',
});

function layout(rows: Check[], value = '3') {
    return (
        <SiteSectionsLayout
            site={{ ...sites[0]!, checks: rows }}
            client={clients[0]!}
            checks={rows}
            value={value}
            canOpenSettings
            maintenance={null}
        >
            <div>Check body</div>
        </SiteSectionsLayout>
    );
}

function railLinks() {
    return within(
        screen.getAllByRole('navigation', { name: 'Site sections' })[0]!,
    )
        .getAllByRole('link')
        .map((link) => link.textContent);
}

beforeEach(async () => {
    await i18n.changeLanguage('en');
});
afterEach(() => {
    cleanup();
    listeners.clear();
});

it('groups checks by attention with truthful short states, one announcement per state and the drawer below lg', () => {
    render(layout([uptime, certificate, brokenLinks, domain, sitemap, dns]), {
        wrapper: Wrapper,
    });

    // Failed before Warning, then no current data; canonical order inside a severity.
    expect(railLinks()).toEqual([
        'Overview',
        'SitemapFailed',
        'Broken links15 broken',
        'CertificateExpires in 9 days',
        'DomainExpires Dec 11, 2026',
        'DNSNo current data',
        'UptimeUp',
        'Settings',
    ]);
    expect(
        screen
            .getByRole('link', { name: /Broken links/ })
            .getAttribute('aria-current'),
    ).toBe('page');
    // The short state already says "Failed": the status shape is not announced a second time.
    expect(screen.getByRole('link', { name: /^Sitemap/ }).textContent).toBe(
        'SitemapFailed',
    );
    expect(
        within(screen.getByRole('link', { name: /^Sitemap/ })).queryByRole(
            'img',
        ),
    ).toBeNull();
    // A count is not the state word, so its shape keeps its label.
    expect(
        within(screen.getByRole('link', { name: /Broken links/ })).getByRole(
            'img',
            { name: 'Failed' },
        ),
    ).not.toBeNull();

    fireEvent.click(
        screen.getByRole('button', { name: /Switch site section/ }),
    );
    expect(
        within(screen.getByRole('dialog')).getByRole('navigation', {
            name: 'Site sections',
        }),
    ).not.toBeNull();
});

it('moves only the check this tab wrote to and never moves foreign changes or new arrivals', () => {
    const { rerender } = render(layout([uptime, certificate, brokenLinks]), {
        wrapper: Wrapper,
    });
    const initial = [
        'Overview',
        'Broken links15 broken',
        'CertificateExpires in 9 days',
        'UptimeUp',
        'Settings',
    ];
    expect(railLinks()).toEqual(initial);

    // A foreign recovery changes the state in place; the check keeps its group.
    const recovered = {
        ...brokenLinks,
        state: 'ok' as const,
        result: 'succeeded' as const,
        presentation: null,
    };
    rerender(layout([uptime, certificate, recovered]));
    expect(railLinks()).toEqual([
        'Overview',
        'Broken linksOK',
        'CertificateExpires in 9 days',
        'UptimeUp',
        'Settings',
    ]);

    // A check that arrives on a refresh goes to the end of its group once and stays there.
    const failedDns = check(6, {
        key: 'dns',
        label_key: 'checks.dns.label',
        state: 'failed',
        result: 'failed',
    });
    rerender(layout([failedDns, uptime, certificate, recovered]));
    rerender(layout([failedDns, uptime, certificate, recovered]));
    const withArrival = [
        'Overview',
        'Broken linksOK',
        'CertificateExpires in 9 days',
        'DNSFailed',
        'UptimeUp',
        'Settings',
    ];
    expect(railLinks()).toEqual(withArrival);

    // A run requested here moves nothing when it is accepted; its result moves on the next visit.
    emit('before', {
        visit: {
            id: 'run',
            method: 'post',
            url: new URL('http://h/sites/1/checks/2/run'),
        },
    });
    emit('success', { visitId: 'run' });
    expect(railLinks()).toEqual(withArrival);

    // This tab turns Certificate off while a foreign Uptime outage arrives in the same response:
    // only Certificate moves (Off, last in Other checks); Uptime stays where it was.
    emit('before', {
        visit: {
            id: 'off',
            method: 'post',
            url: new URL('http://h/sites/1/checks/2/disable'),
        },
    });
    const off = { ...certificate, enabled: false, state: 'disabled' as const };
    const down = {
        ...uptime,
        state: 'down' as const,
        result: 'failed' as const,
    };
    rerender(layout([failedDns, down, off, recovered]));
    emit('success', { visitId: 'off' });
    expect(railLinks()).toEqual([
        'Overview',
        'Broken linksOK',
        'DNSFailed',
        'UptimeDown',
        'CertificateOff',
        'Settings',
    ]);

    // A write to another site's check is not this rail's own change.
    emit('before', {
        visit: {
            id: 'other',
            method: 'post',
            url: new URL('http://h/sites/99/checks/3/snooze'),
        },
    });
    emit('success', { visitId: 'other' });
    expect(railLinks()).toEqual([
        'Overview',
        'Broken linksOK',
        'DNSFailed',
        'UptimeDown',
        'CertificateOff',
        'Settings',
    ]);
});
