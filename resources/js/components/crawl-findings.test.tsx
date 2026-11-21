import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { FindingsTable } from './crawl-findings';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) =>
            params ? `${key} ${JSON.stringify(params)}` : key,
        i18n: { language: 'en' },
    }),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC', locale: 'en' } }),
    router: { on: () => () => {}, visit: () => {} },
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

// The findings card toolbar reads the viewport width (CardToolbarActions); jsdom has no matchMedia.
vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});

afterEach(cleanup);

/** The shared tooltip a trigger opens on keyboard focus (the replacement for a native title). */
async function tooltipOf(trigger: Element): Promise<string | null> {
    await act(async () => {
        fireEvent.focus(trigger);
    });
    const text = screen.queryByRole('tooltip')?.textContent ?? null;
    await act(async () => {
        fireEvent.blur(trigger);
    });
    return text;
}

const sitemapRows = [
    { url: 'https://demo-01.example/products/old', status: 404 },
    { url: 'htp:/demo-01.example//broken loc', status: 'Invalid' },
    { url: 'https://legacy.example/archive', status: 'TLS' },
];

it('links sitemap URLs without finding details (incident 80)', () => {
    render(
        <FindingsTable
            stackWhenNarrow={false}
            showSource={false}
            origin="https://demo-01.example"
            rows={sitemapRows}
        />,
    );
    const hrefs = screen
        .getAllByRole('link')
        .map((link) => link.getAttribute('href'));
    expect(hrefs).toEqual([
        'https://demo-01.example/products/old',
        'https://legacy.example/archive',
    ]);
    expect(screen.getByText('htp:/demo-01.example//broken loc')).toBeTruthy();
});

it('keeps URLs as text when linkUrls is off', () => {
    render(
        <FindingsTable
            stackWhenNarrow={false}
            showSource={false}
            linkUrls={false}
            rows={sitemapRows}
        />,
    );
    expect(screen.queryAllByRole('link')).toHaveLength(0);
});

it('shows the empty text instead of an empty table, and the shared pager for one page of a longer list', () => {
    const { unmount } = render(<FindingsTable rows={[]} empty="No issues" />);
    expect(screen.getByText('No issues')).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
    unmount();
    render(
        <FindingsTable
            stackWhenNarrow={false}
            rows={sitemapRows}
            pagination={{
                current_page: 2,
                per_page: 10,
                total: 40,
                last_page: 4,
                next_page_url: '/sites/1/checks/2?findings_page=3',
                previous_page_url: '/sites/1/checks/2?findings_page=1',
                page_name: 'findings_page',
            }}
        />,
    );
    expect(screen.getByText(/of \{\{total\}\}/).textContent).toContain(
        '"from":"11","to":"20","total":"40"',
    );
});

it('shows same-origin URLs as path, keeps others absolute, and keeps href and tooltip absolute', async () => {
    const rows = [
        { url: 'https://demo-01.example/checkout/offsite/paypal', status: 404 },
        { url: 'https://demo-01.example/a?b=c#d', status: 404 },
        { url: 'https://demo-01.example', status: 404 },
        { url: 'https://partner.example.org/x', status: 404 },
        { url: 'http://demo-01.example/plain', status: 404 },
        { url: 'https://www.demo-01.example/www', status: 404 },
        { url: 'https://demo-01.example:8443/port', status: 404 },
        { url: 'htp:/demo-01.example//broken loc', status: 'Invalid' },
    ];
    render(
        <FindingsTable
            stackWhenNarrow={false}
            showSource={false}
            origin="https://demo-01.example"
            rows={rows}
        />,
    );
    const links = screen.getAllByRole('link');
    expect(
        links.map(
            (link) => link.querySelector('[data-link-text]')?.textContent,
        ),
    ).toEqual([
        '/checkout/offsite/paypal',
        '/a?b=c#d',
        '/',
        'https://partner.example.org/x',
        'http://demo-01.example/plain',
        'https://www.demo-01.example/www',
        'https://demo-01.example:8443/port',
    ]);
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
        'https://demo-01.example/checkout/offsite/paypal',
        'https://demo-01.example/a?b=c#d',
        'https://demo-01.example/',
        'https://partner.example.org/x',
        'http://demo-01.example/plain',
        'https://www.demo-01.example/www',
        'https://demo-01.example:8443/port',
    ]);
    const titles: (string | null)[] = [];
    for (const link of links) {
        expect(link.hasAttribute('title')).toBe(false);
        titles.push(await tooltipOf(link));
    }
    expect(titles).toEqual(rows.slice(0, 7).map((row) => row.url));
    expect(
        screen.getByText('htp:/demo-01.example//broken loc').closest('a'),
    ).toBeNull();
});

it('bounds rendered link text and text-fragment anchors without materializing huge strings', () => {
    const huge = '🚀'.repeat(20_000);
    render(
        <FindingsTable
            stackWhenNarrow
            showSource
            showFindingDetails
            origin="https://demo-01.example"
            rows={[
                {
                    url: 'https://demo-01.example/broken',
                    status: 404,
                    source: 'https://demo-01.example/page',
                    linkText: huge,
                },
            ]}
        />,
    );
    const cells = screen.getAllByText(/🚀/);
    for (const cell of cells) {
        expect((cell.textContent ?? '').length).toBeLessThan(1000);
    }
    const sourceLinks = screen
        .getAllByRole('link')
        .filter((link) =>
            link
                .getAttribute('href')
                ?.startsWith('https://demo-01.example/page#:~:text='),
        );
    expect(sourceLinks.length).toBeGreaterThan(0);
    for (const link of sourceLinks) {
        const encoded = link.getAttribute('href')?.split('#:~:text=')[1] ?? '';
        expect(Array.from(decodeURIComponent(encoded))).toHaveLength(200);
    }
});
