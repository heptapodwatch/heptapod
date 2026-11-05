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

const sitemapSchema: Heptapod.Contracts.Schema.Schema = {
    fields: {
        urls_per_run: {
            type: 'string',
            rules: ['sometimes', 'in:0,100,500,1000,max'],
            properties: {},
            items: null,
            presentation: {
                labelKey: 'sitemap.urls_per_run',
                helpKey: null,
                options: {
                    '0': 'sitemap.urls_per_run.0',
                    '100': 'sitemap.urls_per_run.100',
                    '500': 'sitemap.urls_per_run.500',
                    '1000': 'sitemap.urls_per_run.1000',
                    max: 'sitemap.urls_per_run.max',
                },
            },
            additionalProperties: false,
            targetUrl: false,
            default: '500',
            uppercase: false,
            trimmedStrings: false,
        },
    },
};

function sitemapCheck(
    intervalSeconds: number,
): Heptapod.Data.Ui.CheckSettingsData {
    const site = sites[0]!;
    const fixture = checkTabPageFixture(site, site.checks[0]!);
    return {
        ...fixture.check_settings,
        check_key: 'sitemap',
        config_schema: sitemapSchema,
        settings: {},
        interval_seconds: intervalSeconds,
    };
}

async function openSheet(
    locale: 'en' | 'de',
    intervalSeconds: number,
    discoveredUrls: number | null,
) {
    await i18n.changeLanguage(locale);
    render(
        <I18nextProvider i18n={i18n}>
            <CheckSettingsSheet
                check={sitemapCheck(intervalSeconds)}
                discoveredUrls={discoveredUrls}
            />
        </I18nextProvider>,
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: locale === 'de' ? 'Konfigurieren' : 'Configure',
        }),
    );
}

function helperLine(): string {
    const group = screen.getByRole('radiogroup', {
        name: /URLs checked per run|Geprüfte URLs pro Durchlauf/,
    });
    const help = group.getAttribute('aria-describedby')?.split(' ')[0];
    return document.getElementById(help ?? '')?.textContent ?? '';
}

it.each([
    // ⌈1663 / 500⌉ = 4 runs × 1 day.
    [
        'en',
        86400,
        1663,
        'At 500 per run, all 1,663 URLs are covered every 4 days.',
    ],
    // ⌈1663 / 500⌉ = 4 runs × 6 hours = one day.
    [
        'en',
        21600,
        1663,
        'At 500 per run, all 1,663 URLs are covered every day.',
    ],
    // ⌈1663 / 500⌉ = 4 runs × 2 hours.
    [
        'en',
        7200,
        1663,
        'At 500 per run, all 1,663 URLs are covered every 8 hours.',
    ],
    // ⌈1663 / 500⌉ = 4 runs × 30 minutes = 2 hours.
    [
        'en',
        1800,
        1663,
        'At 500 per run, all 1,663 URLs are covered every 2 hours.',
    ],
    // ⌈501 / 500⌉ = 2 runs × 15 minutes.
    [
        'en',
        900,
        501,
        'At 500 per run, all 501 URLs are covered every 30 minutes.',
    ],
    ['en', 86400, 500, 'All 500 URLs are checked on every run.'],
    ['en', 86400, 1, 'All 1 URL is checked on every run.'],
    [
        'en',
        86400,
        null,
        'Each run checks the next 500 URLs, continuing where the last run stopped.',
    ],
    [
        'de',
        86400,
        1663,
        'Bei 500 pro Durchlauf werden alle 1.663 URLs alle 4 Tage vollständig geprüft.',
    ],
    [
        'de',
        7200,
        1663,
        'Bei 500 pro Durchlauf werden alle 1.663 URLs alle 8 Stunden vollständig geprüft.',
    ],
    [
        'de',
        300,
        1001,
        'Bei 500 pro Durchlauf werden alle 1.001 URLs alle 15 Minuten vollständig geprüft.',
    ],
    [
        'de',
        43200,
        1000,
        'Bei 500 pro Durchlauf werden alle 1.000 URLs jeden Tag vollständig geprüft.',
    ],
    ['de', 86400, 1, 'Die 1 URL wird bei jedem Durchlauf geprüft.'],
] as const)(
    'explains the full-pass span in %s at a %d s interval for %s URLs',
    async (locale, intervalSeconds, discoveredUrls, expected) => {
        await openSheet(locale, intervalSeconds, discoveredUrls);

        expect(helperLine()).toBe(expected);
    },
);

it('follows the draft interval and choice, and saves the chosen string value', async () => {
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
    await openSheet('en', 86400, 1663);

    fireEvent.click(screen.getByRole('radio', { name: '100' }));
    expect(helperLine()).toBe(
        'At 100 per run, all 1,663 URLs are covered every 17 days.',
    );
    fireEvent.click(screen.getByRole('radio', { name: 'Hours' }));
    expect(helperLine()).toBe(
        'At 100 per run, all 1,663 URLs are covered every 17 hours.',
    );
    fireEvent.click(
        screen.getByRole('radio', {
            name: 'Structure only (no page requests)',
        }),
    );
    expect(helperLine()).toBe(
        'No pages are requested; only the sitemap files are checked.',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(patch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ urls_per_run: '0', frequency_unit: 'hour' }),
        expect.any(Object),
    );
});
