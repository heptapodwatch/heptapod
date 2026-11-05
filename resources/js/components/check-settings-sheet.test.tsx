import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import { router } from '@inertiajs/react';
import { CheckSettingsSheet } from './check-settings-sheet';
import { checkTabPageFixture, sites } from '@/fixtures/design';
import i18n from '@/lib/i18n';

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

it('edits the existing check interval in its Configure sheet and submits both schedule fields', async () => {
    await i18n.changeLanguage('en');
    const site = sites[0]!;
    const fixture = checkTabPageFixture(site, site.checks[0]!);
    const check = { ...fixture.check_settings, interval_seconds: 3600 };
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
    render(
        <I18nextProvider i18n={i18n}>
            <CheckSettingsSheet check={check} discoveredUrls={null} />
        </I18nextProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Configure' }));
    const interval = screen.getByLabelText(
        'Check interval',
    ) as HTMLInputElement;
    expect(interval.value).toBe('1');
    expect(
        (
            screen.getByRole('radio', { name: 'Hours' }) as HTMLButtonElement
        ).getAttribute('aria-checked'),
    ).toBe('true');
    fireEvent.change(interval, { target: { value: '2' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Days' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(patch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ frequency_value: 2, frequency_unit: 'day' }),
        expect.any(Object),
    );
});

it('discards an interval draft when Cancel closes Configure', async () => {
    await i18n.changeLanguage('en');
    const site = sites[0]!;
    const fixture = checkTabPageFixture(site, site.checks[0]!);
    const check = { ...fixture.check_settings, interval_seconds: 60 };
    render(
        <I18nextProvider i18n={i18n}>
            <CheckSettingsSheet check={check} discoveredUrls={null} />
        </I18nextProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Configure' }));
    fireEvent.change(screen.getByLabelText('Check interval'), {
        target: { value: '5' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Configure' }));

    expect(
        (screen.getByLabelText('Check interval') as HTMLInputElement).value,
    ).toBe('1');
});
