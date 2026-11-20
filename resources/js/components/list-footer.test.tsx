import { cleanup, render, screen } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import type { ComponentProps } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { ListFooter } from './list-footer';
import i18n from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        prefetch: _prefetch,
        preserveScroll: _preserveScroll,
        preserveState: _preserveState,
        only: _only,
        ...props
    }: ComponentProps<'a'> & {
        prefetch?: boolean;
        preserveScroll?: boolean;
        preserveState?: boolean;
        only?: string[];
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

afterEach(cleanup);

function renderFooter(props: ComponentProps<typeof ListFooter>) {
    render(
        <I18nextProvider i18n={i18n}>
            <ListFooter {...props} />
        </I18nextProvider>,
    );
}

const pagination = (
    overrides: Partial<Heptapod.Contracts.PaginationData> = {},
): Heptapod.Contracts.PaginationData => ({
    current_page: 1,
    per_page: 50,
    total: 18,
    last_page: 1,
    next_page_url: null,
    previous_page_url: null,
    page_name: 'page',
    ...overrides,
});

it('shows only the count on a single page', async () => {
    await i18n.changeLanguage('en');
    renderFooter({ count: '18 sites', pagination: pagination() });

    expect(screen.getByText('18 sites')).not.toBeNull();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Next page' })).toBeNull();
});

it.each([
    ['en', '51–100 of 120', 'Page 2 of 3', 'Previous page', 'Next page'],
    [
        'de',
        '51–100 von 120',
        'Seite 2 von 3',
        'Vorherige Seite',
        'Nächste Seite',
    ],
])(
    'shows the range and pages a numbered list in %s',
    async (locale, range, position, previous, next) => {
        await i18n.changeLanguage(locale);
        renderFooter({
            count: '120 sites',
            pagination: pagination({
                current_page: 2,
                total: 120,
                last_page: 3,
                previous_page_url: '/sites?page=1',
                next_page_url: '/sites?page=3',
            }),
        });

        expect(screen.getByText(range)).not.toBeNull();
        expect(screen.queryByText('120 sites')).toBeNull();
        expect(screen.getByText(position)).not.toBeNull();
        expect(
            screen.getByRole('link', { name: previous }).getAttribute('href'),
        ).toBe('/sites?page=1');
        expect(
            screen.getByRole('link', { name: next }).getAttribute('href'),
        ).toBe('/sites?page=3');
    },
);

it('disables the pager at the last page and offers Rows only when a size changes what is shown', async () => {
    await i18n.changeLanguage('en');
    renderFooter({
        count: '64 findings',
        pagination: pagination({
            current_page: 2,
            per_page: 50,
            total: 64,
            last_page: 2,
            previous_page_url: '/sites/1/checks/2?broken_links_page=1',
            page_name: 'broken_links_page',
        }),
    });
    expect(
        (screen.getByRole('button', { name: 'Next page' }) as HTMLButtonElement)
            .disabled,
    ).toBe(true);
    expect(screen.getByText('Rows')).not.toBeNull();

    cleanup();
    renderFooter({ count: '9 tasks', pagination: pagination({ total: 9 }) });
    expect(screen.queryByText('Rows')).toBeNull();
});

it('offers only the next page for a cursor list', async () => {
    await i18n.changeLanguage('en');
    renderFooter({
        count: '20 runs on this page',
        next: { href: '/runs?before=42', only: ['runs'] },
    });

    expect(screen.queryByText(/Previous page|Page \d/)).toBeNull();
    expect(screen.getByRole('link', { name: 'Next page' })).not.toBeNull();
});
