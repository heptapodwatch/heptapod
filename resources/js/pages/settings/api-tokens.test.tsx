import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import ApiTokens from '@/pages/settings/api-tokens';

const posts = vi.hoisted(() => [] as Record<string, unknown>[]);
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ props: {} }),
    router: { on: () => () => {} },
    useHttp: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setFormData] = useState(initial);
        let transform = (draft: T): Record<string, unknown> => draft;
        return {
            data,
            errors: {},
            processing: false,
            setData: (key: keyof T, value: unknown) =>
                setFormData((current) => ({ ...current, [key]: value })),
            transform: (callback: typeof transform) => {
                transform = callback;
            },
            post: async () => {
                posts.push(transform(data));
                return { token: 'secret' };
            },
            get: () => new Promise(() => {}),
            delete: async () => null,
            cancel: () => {},
        };
    },
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

beforeEach(() => {
    posts.length = 0;
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

const page: Heptapod.Data.Ui.ApiTokenSettingsData = {
    tokens: [
        {
            id: 1,
            name: 'Deploy bot',
            abilities: ['checks:run', 'monitors:read'],
            client_id: null,
            client_name: null,
            created_at: '2030-01-01T00:00:00Z',
            last_used_at: null,
            expires_at: null,
        },
    ],
    abilities: ['checks:run', 'monitors:read'],
    presets: {
        read: ['monitors:read'],
        write: ['monitors:read'],
        manage: ['monitors:read'],
        'agent-read': ['monitors:read'],
    },
    clients: [],
    timezone: 'Europe/Berlin',
};

it.each([
    // Berlin is UTC+1 in January and UTC+2 in July.
    ['2030-01-02 03:04', '2030-01-02T02:04:00.000Z'],
    ['2030-07-02 03:04', '2030-07-02T01:04:00.000Z'],
    // 02:30 does not exist on 29 March (spring gap): it moves forward to 03:30 CEST.
    ['2026-03-29 02:30', '2026-03-29T01:30:00.000Z'],
    // 02:30 exists twice on 25 October (autumn overlap): the first occurrence (CEST) wins.
    ['2026-10-25 02:30', '2026-10-25T00:30:00.000Z'],
])(
    'takes the expiry %s in the account timezone and submits %s',
    async (typed, instant) => {
        render(
            <TooltipProvider>
                <ApiTokens tokensPage={page} />
            </TooltipProvider>,
        );
        fireEvent.click(
            screen.getAllByRole('button', { name: /Create token/ })[0]!,
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Agent' },
        });
        const expiry = screen.getByLabelText('Expires at (optional)');
        fireEvent.change(expiry, { target: { value: typed } });
        expect(screen.queryByText(/Enter UTC/)).toBeNull();
        await act(async () => {
            fireEvent.submit(expiry.closest('form')!);
        });
        expect(posts.map((post) => post.expires_at)).toEqual([instant]);
    },
);

it('names token abilities in words and keeps the API scope in the tooltip', () => {
    render(
        <TooltipProvider>
            <ApiTokens tokensPage={page} />
        </TooltipProvider>,
    );
    const abilities = screen.getAllByRole('list', { name: 'Can' })[0]!;
    expect(
        within(abilities)
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual([
        'api_tokens.ability.checks_run',
        'api_tokens.ability.monitors_read',
    ]);
    expect(abilities.textContent).not.toContain('checks:run');
});
