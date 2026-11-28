import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import { router } from '@inertiajs/react';
import { CheckSettingsSheet } from './check-settings-sheet';
import { checkTabPageFixture, sites } from '@/fixtures/design';
import i18n from '@/lib/i18n';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));

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

function chooseInterval(name: string) {
    fireEvent.keyDown(
        screen.getByRole('combobox', { name: 'Check interval' }),
        { key: 'Enter' },
    );
    fireEvent.keyDown(screen.getByRole('option', { name }), { key: 'Enter' });
}

async function openSchedule(seconds: number, presets = [1, 5, 15, 60]) {
    await i18n.changeLanguage('en');
    const site = sites[0]!;
    const fixture = checkTabPageFixture(site, site.checks[0]!);
    const check = {
        ...fixture.check_settings,
        interval_seconds: seconds,
        interval_presets_minutes: presets,
    };
    render(
        <I18nextProvider i18n={i18n}>
            <CheckSettingsSheet check={check} discoveredUrls={null} />
        </I18nextProvider>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Configure' }));
}

it('starts on a matching preset and saves another preset through the existing schedule fields', async () => {
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
    await openSchedule(86400, [360, 720, 1440, 10080]);

    expect(
        screen.getByRole('combobox', { name: 'Check interval' }).textContent,
    ).toBe('Every day');
    expect(screen.queryByLabelText('Interval amount')).toBeNull();
    chooseInterval('Every 7 days');
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(patch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ frequency_value: 7, frequency_unit: 'day' }),
        expect.any(Object),
    );
});

it('opens a non-preset interval as Custom and saves the entered amount and unit', async () => {
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
    await openSchedule(7200);

    expect(
        screen.getByRole('combobox', { name: 'Check interval' }).textContent,
    ).toBe('Custom…');
    const amount = screen.getByLabelText('Interval amount');
    expect(amount).toMatchObject({ value: '2' });
    expect(
        screen
            .getByRole('radio', { name: 'Hours' })
            .getAttribute('aria-checked'),
    ).toBe('true');
    fireEvent.change(amount, { target: { value: '3' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Days' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(patch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ frequency_value: 3, frequency_unit: 'day' }),
        expect.any(Object),
    );
});

it('discards a custom interval draft and resets its choice when Cancel closes Configure', async () => {
    await openSchedule(60);

    chooseInterval('Custom…');
    expect(
        screen.getByRole<HTMLButtonElement>('button', { name: 'Save' })
            .disabled,
    ).toBe(true);
    fireEvent.change(screen.getByLabelText('Interval amount'), {
        target: { value: '5' },
    });
    expect(
        screen.getByRole('combobox', { name: 'Check interval' }).textContent,
    ).toBe('Custom…');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Configure' }));

    expect(
        screen.getByRole('combobox', { name: 'Check interval' }).textContent,
    ).toBe('Every minute');
    expect(screen.queryByLabelText('Interval amount')).toBeNull();
});
