import { renderHook } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import { useFormat } from '@/hooks/use-format';
import { checkSettingsSummary } from '@/lib/check-setting-facts';
import i18n from '@/lib/i18n';
import type { CheckKey } from '@/types/monitoring';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

function field(
    type: Heptapod.Contracts.Schema.FieldType,
    labelKey: string,
    fieldDefault: Heptapod.Contracts.Schema.Field['default'],
    options: Record<string, string> = {},
): Heptapod.Contracts.Schema.Field {
    return {
        type,
        rules: [],
        properties: {},
        items: null,
        presentation: { labelKey, helpKey: null, options },
        additionalProperties: false,
        targetUrl: false,
        default: fieldDefault,
        uppercase: false,
        trimmedStrings: false,
    };
}

const schemas: Partial<
    Record<CheckKey, Heptapod.Contracts.Schema.Schema['fields']>
> = {
    performance: {
        threshold_in_ms: field('integer', 'Threshold', 2000),
        change_percentage: field('integer', 'performance.minimum_increase', 50),
    },
    domain: {
        expires_soon_threshold_in_days: field(
            'integer',
            'domain.threshold',
            30,
        ),
    },
    certificate_health: {
        expires_soon_threshold_in_days: field(
            'integer',
            'certificate_health.threshold',
            14,
        ),
    },
    sitemap: {
        path: field('string', 'sitemap.path', '/sitemap.xml'),
        speed: field('string', 'sitemap.speed', 'default', {
            default: 'sitemap.speed.default',
            slow: 'sitemap.speed.slow',
        }),
        urls_per_run: field('string', 'sitemap.urls_per_run', '500', {
            '100': 'sitemap.urls_per_run.100',
            '500': 'sitemap.urls_per_run.500',
        }),
    },
    broken_links: {
        max_pages: field('integer', 'crawl.settings.max_pages', 2000),
    },
    lighthouse: {
        device_emulation: field('string', 'lighthouse.device', 'mobile', {
            mobile: 'Mobile',
            desktop: 'Desktop',
        }),
    },
    mixed_content: {},
};

function summary(
    locale: 'en' | 'de',
    key: CheckKey,
    intervalSeconds: number,
    settings: Record<string, unknown> = {},
): string {
    void i18n.changeLanguage(locale);
    const { result } = renderHook(() => useFormat(), {
        wrapper: ({ children }: { children: ReactNode }) => (
            <I18nextProvider i18n={i18n}>{children}</I18nextProvider>
        ),
    });
    return checkSettingsSummary(
        {
            site_id: 1,
            check_id: 2,
            check_key: key,
            label_key: `checks.${key}.label`,
            enabled: true,
            interval_seconds: intervalSeconds,
            config_schema: { fields: schemas[key] ?? {} },
            settings,
            can_update: true,
        },
        i18n.t,
        result.current,
    ).join(' · ');
}

it.each([
    ['en', 'performance', 60, {}, 'Every minute · Threshold: 2,000 ms'],
    [
        'de',
        'performance',
        300,
        { threshold_in_ms: 3500 },
        'Alle 5 Minuten · Schwellenwert: 3.500 ms',
    ],
    [
        'en',
        'domain',
        86400,
        {},
        'Every day · Days before domain expiry: 30 days',
    ],
    [
        'de',
        'certificate_health',
        172800,
        { expires_soon_threshold_in_days: 1 },
        'Alle 2 Tage · Warnen vor Ablauf: 1 Tag',
    ],
    [
        'en',
        'sitemap',
        3600,
        { urls_per_run: '100', speed: 'slow' },
        'Every hour · URLs checked per run: 100 · Additional delay between requests: Slow (50 ms)',
    ],
    ['en', 'broken_links', 86400, {}, 'Every day · Maximum pages: 2,000'],
    ['de', 'broken_links', 86400, {}, 'Täglich · Maximale Seiten: 2.000'],
    ['en', 'mixed_content', 5400, {}, 'Every 1 hr, 30 min'],
    ['de', 'lighthouse', 86400, {}, 'Täglich · Gerät: Mobil'],
    [
        'en',
        'lighthouse',
        86400,
        { device_emulation: 'desktop' },
        'Every day · Device: Desktop',
    ],
] as const)(
    '%s %s summary: interval then the type key settings with effective values',
    (locale, key, interval, settings, expected) => {
        expect(summary(locale, key, interval, settings)).toBe(expected);
    },
);
