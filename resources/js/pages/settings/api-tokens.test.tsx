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
                return { token: 'secret', expires_at: null };
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
    abilities: [
        'identity:read',
        'monitors:read',
        'checks:read',
        'checks:run',
        'maintenance:read',
        'maintenance:write',
    ],
    presets: {
        read: ['monitors:read'],
        write: ['monitors:read'],
        manage: ['monitors:read'],
        'agent-read': ['monitors:read'],
    },
    starting_points: {
        'read-everything': [
            'identity:read',
            'monitors:read',
            'checks:read',
            'maintenance:read',
        ],
        'deploy-automation': [
            'monitors:read',
            'checks:read',
            'checks:run',
            'maintenance:read',
            'maintenance:write',
        ],
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
        fireEvent.click(
            screen.getByLabelText(
                'All clients you can access, including future clients',
            ),
        );
        fireEvent.click(screen.getByLabelText('Custom date'));
        const expiry = screen.getByLabelText('Expires at');
        fireEvent.change(expiry, { target: { value: typed } });
        expect(screen.queryByText(/Enter UTC/)).toBeNull();
        await act(async () => {
            fireEvent.submit(expiry.closest('form')!);
        });
        expect(posts.map((post) => post.expires_at)).toEqual([instant]);
    },
);

it('issues exactly the edited checklist with the chosen client access and a 30-day default expiry', async () => {
    render(
        <TooltipProvider>
            <ApiTokens tokensPage={page} />
        </TooltipProvider>,
    );
    fireEvent.click(
        screen.getAllByRole('button', { name: /Create token/ })[0]!,
    );
    fireEvent.change(screen.getByLabelText('Name'), {
        target: { value: 'Deploy' },
    });
    fireEvent.click(screen.getByLabelText('Deploy automation'));
    // Leave out the maintenance lookup and add the identity read: the grant is the checklist, not the starting point.
    fireEvent.click(screen.getByRole('checkbox', { name: /maintenance:read/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: /identity:read/ }));
    const form = screen.getByLabelText('Name').closest('form')!;
    await act(async () => {
        fireEvent.submit(form);
    });
    // Client access has no default.
    expect(posts).toEqual([]);
    expect(
        screen.getByText('Choose which clients the token can reach.'),
    ).not.toBeNull();
    fireEvent.click(
        screen.getByLabelText(
            'All clients you can access, including future clients',
        ),
    );
    await act(async () => {
        fireEvent.submit(form);
    });
    expect(posts).toEqual([
        {
            name: 'Deploy',
            abilities: [
                'identity:read',
                'monitors:read',
                'checks:read',
                'checks:run',
                'maintenance:write',
            ],
            client_id: null,
            expires_at: null,
            expires_in_days: 30,
        },
    ]);
});

it('lists stored grants behind a count with plain names and raw scopes', () => {
    render(
        <TooltipProvider>
            <ApiTokens
                tokensPage={{
                    ...page,
                    tokens: [
                        page.tokens[0]!,
                        { ...page.tokens[0]!, id: 2, abilities: ['*'] },
                    ],
                }}
            />
        </TooltipProvider>,
    );
    expect(screen.queryAllByRole('list', { name: 'Can' })).toHaveLength(0);
    fireEvent.click(screen.getByText('api_tokens.grants.count'));
    fireEvent.click(screen.getByText('api_tokens.ability.all'));
    const [scoped, wildcard] = screen.getAllByRole('list', { name: 'Can' });
    expect(
        within(scoped!)
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual([
        'api_tokens.ability.checks_runchecks:run',
        'api_tokens.ability.monitors_readmonitors:read',
    ]);
    expect(wildcard!.textContent).toBe('*');
    expect(
        screen.getByText('api_tokens.grants.replace_wildcard'),
    ).not.toBeNull();
});
