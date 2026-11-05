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

it('shows only the count on a single page', async () => {
    await i18n.changeLanguage('en');
    renderFooter({
        count: '18 sites',
        previous: null,
        next: null,
        page: 1,
        lastPage: 1,
    });

    expect(screen.getByText('18 sites')).not.toBeNull();
    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.queryByRole('link')).toBeNull();
});

it.each([
    ['en', 'Page 1 of 3', 'Previous page', 'Next page'],
    ['de', 'Seite 1 von 3', 'Vorherige Seite', 'Nächste Seite'],
])(
    'pages a numbered list with a disabled start and a linked next page in %s',
    async (locale, position, previous, next) => {
        await i18n.changeLanguage(locale);
        renderFooter({
            count: '120',
            previous: null,
            next: { href: '/sites?page=2' },
            page: 1,
            lastPage: 3,
        });

        expect(screen.getByText(position)).not.toBeNull();
        expect(
            (
                screen.getByRole('button', {
                    name: previous,
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        expect(
            screen.getByRole('link', { name: next }).getAttribute('href'),
        ).toBe('/sites?page=2');
    },
);

it('offers only the next page for a cursor list', async () => {
    await i18n.changeLanguage('en');
    renderFooter({
        count: '20 runs on this page',
        next: { href: '/runs?before=42', only: ['runs'] },
    });

    expect(screen.queryByText(/Previous page|Page \d/)).toBeNull();
    expect(screen.getByRole('link', { name: 'Next page' })).not.toBeNull();
});
