import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import { CheckDetail } from './check-detail';
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
    // The header's sheets hold forms (Snooze until, check settings); nothing here submits them.
    useForm: <T,>(data: T) => ({
        data,
        errors: {},
        processing: false,
        reset: () => {},
        clearErrors: () => {},
    }),
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/components/check-settings-facts', () => ({
    CheckScheduleSummary: () => null,
    CheckSettingsFacts: () => null,
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

beforeEach(() => {
    // The settings sheet's radio group measures itself.
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('shows the projected pending state in the check header with a muted previous result and no old change chip', () => {
    render(<CheckDetail page={page(true)} requestedRun={own} />, {
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
    render(<CheckDetail page={page(false)} requestedRun={own} />, {
        wrapper: TooltipProvider,
    });
    expect(
        screen.getByRole('heading', { name: 'Old failed DNS result.' }),
    ).not.toBeNull();
    expect(screen.getByText('Failed', { exact: true })).not.toBeNull();
    expect(screen.getByText('Old change chip')).not.toBeNull();
    expect(screen.queryByText(/Last result: Failed/)).toBeNull();
});

it('opens the check settings from More actions and returns focus to its trigger', async () => {
    render(
        <CheckDetail
            page={page(false)}
            primaryAction={<button type="button">Run check</button>}
            requestedRun={own}
        />,
        { wrapper: TooltipProvider },
    );
    expect(document.querySelector('[data-check-configuration]')).toBeNull();
    const trigger = screen.getByRole('button', { name: 'More actions' });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'Enter' });
    expect(
        await screen.findByRole('menuitem', { name: 'Turn check off…' }),
    ).not.toBeNull();
    const configure = screen.getByRole('menuitem', {
        name: 'Configure check…',
    });
    configure.focus();
    fireEvent.click(configure);
    expect(screen.getByRole('button', { name: 'Save' })).not.toBeNull();

    fireEvent.keyDown(document.activeElement ?? document.body, {
        key: 'Escape',
    });
    await waitFor(() => expect(document.activeElement).toBe(trigger));
});

it('opens the check settings read-only for roles that cannot change them', () => {
    const readOnly = page(false);
    render(
        <CheckDetail
            page={{
                ...readOnly,
                can_run: false,
                check_settings: {
                    ...readOnly.check_settings,
                    can_update: false,
                },
            }}
            requestedRun={own}
        />,
        { wrapper: TooltipProvider },
    );
    expect(screen.queryByRole('button', { name: 'More actions' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Check settings' }));
    expect(
        screen.getByRole('dialog', { name: 'Check settings' }),
    ).not.toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByRole('spinbutton')).toBeNull();
});
