import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import SiteSettings from '@/pages/sites/settings';
import { checkTabPageFixture, clients, sites } from '@/fixtures/design';
import { router } from '@inertiajs/react';
import i18n from '@/lib/i18n';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));
vi.mock('react-i18next', async (importOriginal) => {
    const actual = await importOriginal<typeof import('react-i18next')>();
    const t = (key: string) => key;
    return {
        ...actual,
        useTranslation: () => ({ t, i18n: { language: 'en', t: i18n.t } }),
    };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/hooks/use-live', () => ({ useLiveReload: () => vi.fn() }));
vi.mock('@/components/site-header', () => ({ SiteHeader: () => null }));
vi.mock('@/components/alert-sensitivity-form', () => ({
    SiteAlertSensitivity: () => null,
}));
HTMLElement.prototype.scrollIntoView = vi.fn();
beforeEach(() => {
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
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('shows a failing check indicator in the settings navigation, not just on check pages', () => {
    const site = sites[0]!;
    const sitemap = {
        ...site.checks[0]!,
        id: 114,
        key: 'sitemap' as const,
        label_key: 'checks.sitemap.label',
        enabled: true,
        state: 'failed' as const,
        result: 'failed' as const,
        not_fresh: false,
        freshness_suppressed_by: null,
    };
    const sensitivity = {
        alert_after_minutes: 5,
        alert_recovery_checks: 2,
        inherited: false,
        preset: null,
    };
    render(
        <SiteSettings
            site={{ ...site, checks: [sitemap] }}
            client={clients[0]!}
            clients={clients}
            resume_at={null}
            check_count={1}
            incident_count={0}
            result_count={0}
            run_all_checks={[]}
            maintenance={null}
            icon_fetched_at={null}
            icon_manual_result={null}
            start_redirect_target_url={null}
            start_redirect_summary_key={null}
            alert_sensitivity={sensitivity}
            team_alert_sensitivity={sensitivity}
            alert_sensitivity_presets={[]}
            timezones={['UTC', 'Europe/Berlin']}
            check_settings={[]}
            alert_sensitivity_bounds={{
                minutes_min: 2,
                minutes_max: 60,
                stored_minutes_min: 1,
                stored_minutes_max: 1440,
                recovery_min: 1,
                recovery_max: 10,
            }}
        />,
    );
    const navigation = screen.getByRole('navigation', {
        name: 'Site sections',
    });
    const link = within(navigation).getByRole('link', {
        name: /checks.sitemap.label/,
    });
    expect(link.getAttribute('href')).toBe(`/sites/${site.id}/checks/114`);
    expect(within(link).getByRole('img', { name: 'Failed' })).not.toBeNull();
    expect(
        within(navigation)
            .getByRole('link', { name: 'Settings' })
            .getAttribute('aria-current'),
    ).toBe('page');
});

it('makes the whole check row the toggle target, not only the switch', () => {
    const site = sites[0]!;
    const sensitivity = {
        alert_after_minutes: 5,
        alert_recovery_checks: 2,
        inherited: false,
        preset: null,
    };
    render(
        <SiteSettings
            site={site}
            client={clients[0]!}
            clients={clients}
            resume_at={null}
            check_count={site.checks.length}
            incident_count={0}
            result_count={0}
            run_all_checks={[]}
            maintenance={null}
            icon_fetched_at={null}
            icon_manual_result={null}
            start_redirect_target_url={null}
            start_redirect_summary_key={null}
            alert_sensitivity={sensitivity}
            team_alert_sensitivity={sensitivity}
            alert_sensitivity_presets={[]}
            timezones={['UTC']}
            check_settings={[]}
            alert_sensitivity_bounds={{
                minutes_min: 2,
                minutes_max: 60,
                stored_minutes_min: 1,
                stored_minutes_max: 1440,
                recovery_min: 1,
                recovery_max: 10,
            }}
        />,
    );
    const check = site.checks[0]!;
    const toggle = screen.getByRole('switch', { name: check.label_key });
    const row = toggle.closest('label')!;
    expect(row.getAttribute('for')).toBe(toggle.id);
    expect(row.className).toContain('min-h-10');
    const before = toggle.getAttribute('aria-checked');
    fireEvent.click(within(row).getByText(check.label_key));
    expect(toggle.getAttribute('aria-checked')).not.toBe(before);

    // Space on the focused switch: the browser sends keydown, keyup and one activation click on the button (jsdom does
    // not synthesize that click, so the test sends it). The row label must not forward a second click to the switch.
    const afterRowClick = toggle.getAttribute('aria-checked');
    toggle.focus();
    fireEvent.keyDown(toggle, { key: ' ', code: 'Space' });
    fireEvent.keyUp(toggle, { key: ' ', code: 'Space' });
    fireEvent.click(toggle);
    expect(document.activeElement).toBe(toggle);
    expect(toggle.getAttribute('aria-checked')).not.toBe(afterRowClick);
    expect(toggle.getAttribute('aria-checked')).toBe(before);
});

it('saves a check from its settings row without submitting or discarding the site form draft', async () => {
    const site = sites[0]!;
    const [first, second] = site.checks;
    const check = {
        ...checkTabPageFixture(site, second!).check_settings,
        interval_seconds: 3600,
    };
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
    const sensitivity = {
        alert_after_minutes: 5,
        alert_recovery_checks: 2,
        inherited: false,
        preset: null,
    };
    const page = (checkSettings: Heptapod.Data.Ui.CheckSettingsData[]) => (
        <SiteSettings
            site={site}
            client={clients[0]!}
            clients={clients}
            resume_at={null}
            check_count={site.checks.length}
            incident_count={0}
            result_count={0}
            run_all_checks={[]}
            maintenance={null}
            icon_fetched_at={null}
            icon_manual_result={null}
            start_redirect_target_url={null}
            start_redirect_summary_key={null}
            alert_sensitivity={sensitivity}
            team_alert_sensitivity={sensitivity}
            alert_sensitivity_presets={[]}
            timezones={['UTC']}
            check_settings={checkSettings}
            alert_sensitivity_bounds={{
                minutes_min: 2,
                minutes_max: 60,
                stored_minutes_min: 1,
                stored_minutes_max: 1440,
                recovery_min: 1,
                recovery_max: 10,
            }}
        />
    );
    const { rerender } = render(page([check]));
    const toggle = screen.getByRole('switch', { name: first!.label_key });
    const drafted =
        toggle.getAttribute('aria-checked') === 'true' ? 'false' : 'true';
    fireEvent.click(toggle);
    expect(screen.getAllByText('Unsaved changes').length).toBeGreaterThan(0);
    expect(screen.getByText(/^Every hour/)).not.toBeNull();
    expect(
        screen.queryByRole('button', {
            name: 'checks.settings_section.configure',
        }),
    ).not.toBeNull();
    expect(screen.getAllByRole('button', { name: /configure/i })).toHaveLength(
        1,
    );

    fireEvent.click(
        screen.getByRole('button', {
            name: 'checks.settings_section.configure',
        }),
    );
    fireEvent.change(screen.getByLabelText('Check interval'), {
        target: { value: '2' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(patch).toHaveBeenCalledTimes(1);
    expect(patch).toHaveBeenCalledWith(
        `/sites/${site.id}/checks/${check.check_id}`,
        expect.objectContaining({ frequency_value: 2, frequency_unit: 'hour' }),
        expect.objectContaining({ only: ['check_settings'] }),
    );
    const visit = patch.mock.calls[0]![2]!;
    const saved = { ...check, interval_seconds: 7200 };
    await act(async () => {
        // Inertia delivers only check_settings while preserving the mounted site form.
        rerender(page([saved]));
        await Promise.resolve(
            visit.onSuccess?.(
                {} as Parameters<NonNullable<typeof visit.onSuccess>>[0],
            ),
        );
        visit.onFinish?.(
            {} as Parameters<NonNullable<typeof visit.onFinish>>[0],
        );
    });

    expect(screen.getByText(/^Every 2 hours/)).not.toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(toggle.getAttribute('aria-checked')).toBe(drafted);
    expect(screen.getAllByText('Unsaved changes').length).toBeGreaterThan(0);
});
