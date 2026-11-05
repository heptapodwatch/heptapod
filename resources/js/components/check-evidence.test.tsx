import { cleanup, render, screen } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { afterEach, expect, it, vi } from 'vitest';
import { CheckEvidence } from '@/components/check-evidence';
import i18n from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});
afterEach(cleanup);

it.each([
    ['en', 'The sitemap is not valid XML: syntax error on line 1,234.'],
    ['de', 'Die Sitemap ist kein gültiges XML: Syntaxfehler in Zeile 1.234.'],
] as const)(
    'localizes the XML line number in sitemap evidence in %s',
    async (locale, expected) => {
        await i18n.changeLanguage(locale);
        const detail: Extract<
            Heptapod.Contracts.CheckDetailData,
            { check_type: 'sitemap' }
        > = {
            check_type: 'sitemap',
            checkUrl: 'https://example.test/sitemap.xml',
            issue_summary: 'sitemap.summary.invalid_xml',
            issue_summary_params: { xml_reason: 'syntax', xml_line: 1234 },
            issue_counts: { invalid: 1 },
            totalIssuesCount: 1,
            totalUrlCount: 0,
            hasIssues: true,
            issues: [
                {
                    url: 'https://example.test/sitemap.xml',
                    type: 'sitemap.issue.invalid',
                    xml_reason: 'syntax',
                    xml_line: 1234,
                },
            ],
            sitemapIndexes: [],
            sitemaps: [],
            notes: [],
            export_run: null,
            not_verifiable: [],
        };

        render(
            <I18nextProvider i18n={i18n}>
                <CheckEvidence type="sitemap" detail={detail} history={null} />
            </I18nextProvider>,
        );

        expect(screen.getAllByText(expected).length).toBeGreaterThan(0);
        expect(detail.issues[0]!.xml_line).toBe(1234);
    },
);
