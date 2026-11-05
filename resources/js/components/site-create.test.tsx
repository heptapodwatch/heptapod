import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { router, http } from '@inertiajs/react';
import { addSitePage } from '@/fixtures/design';
import AddSite from '@/pages/sites/create';

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
let userId = 1;
let notificationsCovered = true;
vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();
    return {
        ...original,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: { user: { id: userId }, canManageChannels: true },
                timezone: 'UTC',
                overallHealth: { notifications_covered: notificationsCovered },
            },
        }),
    };
});
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
const options = { ...addSitePage, can_create_site: true };
const storageKey = (id = userId) => `heptapod:site-create:${id}`;
const stored = () => JSON.parse(localStorage.getItem(storageKey()) ?? 'null');
const next = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
const step = (name: string) =>
    fireEvent.click(screen.getByRole('button', { name }));

beforeEach(() => {
    userId = 1;
    notificationsCovered = true;
    localStorage.clear();
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    Element.prototype.scrollIntoView = vi.fn();
    document.cookie = 'XSRF-TOKEN=test';
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                recommended_checks: ['uptime'],
                failure: null,
                status: 200,
            }),
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it.each(['shop.example.test', 'https://shop.example.test'])(
    'restores the ordinary host %s without an omitted-secrets note',
    (url) => {
        const view = render(<AddSite {...options} />);
        fireEvent.change(screen.getByLabelText('URL'), {
            target: { value: url },
        });
        view.unmount();
        render(<AddSite {...options} />);
        expect((screen.getByLabelText('URL') as HTMLInputElement).value).toBe(
            'https://shop.example.test/',
        );
        expect(screen.queryByText('sites.draft.secrets_omitted')).toBeNull();
        expect(stored().omitted).toBe(false);
    },
);

it.each([
    'https://user:private@shop.example.test',
    'https://shop.example.test?token=private',
    'https://shop.example.test#private',
])(
    'restores %s with an omitted-secrets note after removing its private URL parts',
    (url) => {
        const view = render(<AddSite {...options} />);
        fireEvent.change(screen.getByLabelText('URL'), {
            target: { value: url },
        });
        view.unmount();
        render(<AddSite {...options} />);
        expect((screen.getByLabelText('URL') as HTMLInputElement).value).toBe(
            'https://shop.example.test/',
        );
        expect(screen.getByText('sites.draft.secrets_omitted')).not.toBeNull();
        expect(stored().omitted).toBe(true);
    },
);

it('keeps recommendations and probes when an untouched wizard is reopened before entering its address', async () => {
    const view = render(<AddSite {...options} />);
    view.unmount();
    render(<AddSite {...options} />);
    fireEvent.change(screen.getByLabelText('URL'), {
        target: { value: 'https://shop.example.test' },
    });
    next();
    next();
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    expect(
        screen
            .getByRole('switch', { name: /checks.uptime.label/ })
            .getAttribute('aria-checked'),
    ).toBe('true');
});

it('validates forward steps and restores the complete draft and manual check choices after back, forth and reload', async () => {
    const view = render(<AddSite {...options} />);
    step('3. Checks');
    expect(
        screen.getByText(
            'Enter a valid HTTP or HTTPS URL without credentials.',
        ),
    ).not.toBeNull();
    fireEvent.change(screen.getByLabelText('URL'), {
        target: { value: 'https://shop.example.test' },
    });
    next();
    next();
    await waitFor(() => expect(screen.queryByText('Checking site')).toBeNull());
    fireEvent.click(
        screen.getAllByRole('button', {
            name: 'add_site.settings.for_check',
        })[0]!,
    );
    fireEvent.change(screen.getByLabelText('Timeout'), {
        target: { value: '17' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    fireEvent.click(screen.getByRole('switch', { name: /checks.dns.label/ }));
    step('1. Address');
    step('3. Checks');
    expect(
        screen
            .getByRole('switch', { name: /checks.dns.label/ })
            .getAttribute('aria-checked'),
    ).toBe('true');
    view.unmount();
    render(<AddSite {...options} />);
    await waitFor(() => expect(screen.queryByText('Checking site')).toBeNull());
    expect(
        screen
            .getByRole('switch', { name: /checks.dns.label/ })
            .getAttribute('aria-checked'),
    ).toBe('true');
    expect(stored().step).toBe('checks');
    expect(stored().changedChecks).toContain('dns');
    expect(stored().settings['uptime.timeout']).toBe(17);
    fireEvent.click(
        screen.getAllByRole('button', {
            name: 'add_site.settings.for_check',
        })[0]!,
    );
    expect((screen.getByLabelText('Timeout') as HTMLInputElement).value).toBe(
        '17',
    );
    expect(fetch).toHaveBeenCalledTimes(2);
});

it('never stores URL credentials, query, fragment, health secrets, request headers or request bodies', () => {
    const view = render(<AddSite {...options} />);
    fireEvent.change(screen.getByLabelText('URL'), {
        target: {
            value: 'https://user:password@shop.example.test/path?token=private#private',
        },
    });
    expect(localStorage.getItem(storageKey())).not.toMatch(
        /password|token=private|#private|user:/,
    );
    view.unmount();
    const timeout = options.checks.find((check) => check.key === 'uptime')!
        .config_schema.fields.timeout!;
    const nested = {
        ...timeout,
        type: 'object' as const,
        properties: {
            label: { ...timeout, type: 'string' as const },
            token: { ...timeout, type: 'secret' as const },
        },
    };
    const withNested = {
        ...options,
        checks: options.checks.map((check) =>
            check.key === 'uptime'
                ? {
                      ...check,
                      config_schema: {
                          fields: { ...check.config_schema.fields, nested },
                      },
                  }
                : check,
        ),
    };
    localStorage.setItem(
        storageKey(),
        JSON.stringify({
            url: 'https://user:password@shop.example.test/path?token=private#private',
            client_id: String(options.clients[0]!.id),
            step: 'address',
            checks: ['uptime', 'application_health'],
            settings: {
                'application_health.secret': 'health-private',
                'uptime.http_client_headers': [
                    { name: 'Authorization', value: 'header-private' },
                ],
                'uptime.raw_payload': '{"token":"body-private"}',
                'uptime.nested': { label: 'public', token: 'nested-private' },
                'uptime.timeout': 12,
            },
        }),
    );
    render(<AddSite {...withNested} />);
    const draft = localStorage.getItem(storageKey())!;
    expect(draft).not.toMatch(
        /password|token=private|#private|health-private|header-private|body-private|nested-private/,
    );
    expect(stored().settings).toEqual({
        'uptime.timeout': 12,
        'uptime.nested': { label: 'public' },
    });
    expect((screen.getByLabelText('URL') as HTMLInputElement).value).toBe(
        'https://shop.example.test/path',
    );
    expect(screen.getByText('sites.draft.secrets_omitted')).not.toBeNull();
});

it('isolates drafts per user, rejects malformed storage and clamps removed clients and unavailable checks', () => {
    localStorage.setItem(storageKey(), 'not json');
    const view = render(<AddSite {...options} />);
    expect((screen.getByLabelText('URL') as HTMLInputElement).value).toBe('');
    fireEvent.change(screen.getByLabelText('URL'), {
        target: { value: 'https://first.example.test' },
    });
    view.unmount();
    userId = 2;
    localStorage.setItem(
        storageKey(),
        JSON.stringify({
            url: 'https://second.example.test',
            step: 'checks',
            client_id: '-123',
            checks: ['uptime', 'lighthouse', 'not-installed'],
        }),
    );
    render(
        <AddSite
            {...options}
            checks={options.checks.map((check) =>
                check.key === 'lighthouse'
                    ? {
                          ...check,
                          unavailable_reason: 'lighthouse.browser_unavailable',
                      }
                    : check,
            )}
        />,
    );
    expect(stored().step).toBe('client');
    expect(stored().client_id).toBe('');
    expect(stored().checks).toEqual(['uptime']);
    expect(JSON.parse(localStorage.getItem(storageKey(1))!).url).toBe(
        'https://first.example.test/',
    );
});

it('clears the draft only on explicit discard or successful save, not validation failure', async () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    let view = render(<AddSite {...options} />);
    fireEvent.change(screen.getByLabelText('URL'), {
        target: { value: 'https://shop.example.test' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Discard' }));
    expect(localStorage.getItem(storageKey())).toBeNull();
    expect(visit).toHaveBeenCalled();
    view.unmount();
    view = render(<AddSite {...options} />);
    fireEvent.change(screen.getByLabelText('URL'), {
        target: { value: 'https://shop.example.test' },
    });
    next();
    next();
    await waitFor(() => expect(screen.queryByText('Checking site')).toBeNull());
    const post = vi
        .spyOn(router, 'post')
        .mockImplementation((_url, _data, options) => {
            options?.onError?.({ url: 'URL already exists' });
        });
    fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
    expect(localStorage.getItem(storageKey())).not.toBeNull();
    post.mockImplementation((_url, _data, options) => {
        options?.onSuccess?.(
            {} as Parameters<
                NonNullable<NonNullable<typeof options>['onSuccess']>
            >[0],
        );
    });
    step('3. Checks');
    fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
    await waitFor(() => expect(localStorage.getItem(storageKey())).toBeNull());
});

it('creates a client in a sheet with the normal form and selects it without leaving step 2', async () => {
    const request = vi.spyOn(http.getClient(), 'request').mockResolvedValue({
        status: 201,
        headers: { 'content-type': 'application/json' },
        data: JSON.stringify({
            ...options.clients[0],
            id: 987,
            name: 'New Studio',
        }),
    });
    render(<AddSite {...options} />);
    fireEvent.change(screen.getByLabelText('URL'), {
        target: { value: 'shop.example.test' },
    });
    next();
    fireEvent.click(screen.getByRole('combobox', { name: 'Client' }));
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        { target: { value: 'New Studio' } },
    );
    fireEvent.click(
        screen.getByRole('option', { name: 'clients.create_inline' }),
    );
    expect(request).not.toHaveBeenCalled();
    expect(
        (screen.getByLabelText('Client name') as HTMLInputElement).value,
    ).toBe('New Studio');
    fireEvent.click(screen.getByRole('button', { name: 'Create client' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(request).toHaveBeenCalledWith(
        expect.objectContaining({
            url: '/clients',
            method: 'post',
            headers: expect.objectContaining({ Accept: 'application/json' }),
        }),
    );
    expect(JSON.parse(request.mock.calls[0]![0].data as string)).toEqual({
        name: 'New Studio',
        short_name: '',
        contact_name: '',
        locale: 'en',
        timezone: 'UTC',
    });
    expect(
        within(screen.getByRole('combobox', { name: 'Client' })).getByText(
            'New Studio',
        ),
    ).not.toBeNull();
    expect(stored().step).toBe('client');
    expect(stored().client_id).toBe('987');
});

it('cancels client creation without changing the selected client and gives Members no Create entry', () => {
    const view = render(<AddSite {...options} />);
    fireEvent.change(screen.getByLabelText('URL'), {
        target: { value: 'shop.example.test' },
    });
    next();
    const selected = stored().client_id;
    fireEvent.click(screen.getByRole('combobox', { name: 'Client' }));
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        { target: { value: 'Cancelled Studio' } },
    );
    fireEvent.click(
        screen.getByRole('option', { name: 'clients.create_inline' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(stored().client_id).toBe(selected);
    view.unmount();
    render(<AddSite {...options} can_create_site={false} />);
    fireEvent.click(screen.getByRole('combobox', { name: 'Client' }));
    expect(screen.queryByRole('option', { name: /Create/ })).toBeNull();
    expect(screen.queryByText('clients.create_inline')).toBeNull();
});

it('groups checks by consequence, removes unavailable controls and moves Lighthouse into recommendations when enabled on the instance', async () => {
    localStorage.setItem(
        storageKey(),
        JSON.stringify({
            url: 'https://shop.example.test',
            step: 'checks',
            client_id: String(options.clients[0]!.id),
            checks: ['uptime', 'performance'],
        }),
    );
    const checks = options.checks.map((check) =>
        check.key === 'lighthouse'
            ? {
                  ...check,
                  recommended: false,
                  unavailable_reason: 'lighthouse.browser_unavailable',
              }
            : check,
    );
    notificationsCovered = false;
    const view = render(<AddSite {...options} checks={checks} />);
    const unavailable = screen.getByRole('region', {
        name: 'add_site.group.unavailable',
    });
    expect(
        within(unavailable).getByText('checks.lighthouse.label'),
    ).not.toBeNull();
    expect(within(unavailable).queryByRole('switch')).toBeNull();
    expect(
        within(unavailable).getByText('add_site.unavailable.browser_hint'),
    ).not.toBeNull();
    const later = screen.getByRole('region', { name: 'add_site.group.later' });
    expect(within(later).queryByRole('switch')).toBeNull();
    expect(screen.getByText('add_site.no_rules.title')).not.toBeNull();
    expect(screen.getByText('add_site.settings.needs_setup')).not.toBeNull();
    fireEvent.click(
        screen.getByRole('switch', { name: 'checks.uptime.label' }),
    );
    const performance = screen.getByRole('switch', {
        name: 'checks.performance.label',
    });
    expect((performance as HTMLButtonElement).disabled).toBe(true);
    expect(performance.getAttribute('aria-checked')).toBe('false');
    expect(stored().checks).not.toContain('performance');
    notificationsCovered = true;
    view.rerender(
        <AddSite
            {...options}
            checks={checks.map((check) =>
                check.key === 'lighthouse'
                    ? { ...check, recommended: true, unavailable_reason: null }
                    : check,
            )}
        />,
    );
    const recommended = screen.getByRole('region', {
        name: 'add_site.group.recommended',
    });
    expect(
        within(recommended).getByRole('switch', {
            name: 'checks.lighthouse.label',
        }),
    ).not.toBeNull();
    expect(
        screen.queryByRole('region', { name: 'add_site.group.unavailable' }),
    ).toBeNull();
    expect(screen.queryByText('add_site.no_rules.title')).toBeNull();
});

it('groups HTTPS-only checks of an HTTP address under the address, not under instance availability', () => {
    localStorage.setItem(
        storageKey(),
        JSON.stringify({
            url: 'http://shop.example.test',
            step: 'checks',
            client_id: String(options.clients[0]!.id),
            checks: ['uptime'],
        }),
    );
    render(<AddSite {...options} />);
    const address = screen.getByRole('region', {
        name: 'add_site.group.address',
    });
    expect(
        within(address).getByText('checks.certificate_health.label'),
    ).not.toBeNull();
    expect(within(address).queryByRole('switch')).toBeNull();
    expect(
        within(address).getAllByText('add_site.unavailable.https_hint').length,
    ).toBeGreaterThan(0);
    expect(
        screen.queryByRole('region', { name: 'add_site.group.unavailable' }),
    ).toBeNull();
});

it('applies check settings from a sheet, counts only differences from defaults and discards cancelled edits', () => {
    localStorage.setItem(
        storageKey(),
        JSON.stringify({
            url: 'https://shop.example.test',
            step: 'checks',
            client_id: String(options.clients[0]!.id),
            checks: ['uptime'],
        }),
    );
    render(
        <AddSite
            {...options}
            checks={options.checks.map((check) =>
                check.key === 'uptime'
                    ? {
                          ...check,
                          config_schema: {
                              fields: {
                                  ...check.config_schema.fields,
                                  timeout: {
                                      ...check.config_schema.fields.timeout!,
                                      default: 10,
                                  },
                              },
                          },
                      }
                    : check,
            )}
        />,
    );
    const settings = () =>
        screen.getAllByRole('button', {
            name: 'add_site.settings.for_check',
        })[0]!;
    fireEvent.click(settings());
    fireEvent.change(screen.getByLabelText('Timeout'), {
        target: { value: '17' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(stored().settings['uptime.timeout']).toBeUndefined();
    fireEvent.click(settings());
    fireEvent.change(screen.getByLabelText('Timeout'), {
        target: { value: '17' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    expect(screen.getByText('add_site.settings.changed')).not.toBeNull();
    expect(stored().settings['uptime.timeout']).toBe(17);
    fireEvent.click(settings());
    fireEvent.change(screen.getByLabelText('Timeout'), {
        target: {
            value: '10',
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    expect(screen.queryByText('add_site.settings.changed')).toBeNull();
});

it('refreshes probe-dependent recommendations when restoring Checks and preserves explicit check selections', async () => {
    localStorage.setItem(
        storageKey(),
        JSON.stringify({
            url: 'https://shop.example.test',
            step: 'checks',
            client_id: String(options.clients[0]!.id),
            checks: ['uptime', 'application_health'],
            changedChecks: [
                'lighthouse',
                'mixed_content',
                'application_health',
            ],
        }),
    );
    vi.mocked(fetch).mockResolvedValue({
        ok: true,
        json: async () => ({
            recommended_checks: [
                'uptime',
                'broken_links',
                'mixed_content',
                'sitemap',
                'lighthouse',
            ],
            failure: null,
            status: 200,
        }),
    } as Response);
    render(
        <AddSite
            {...options}
            checks={options.checks.map((check) =>
                [
                    'broken_links',
                    'mixed_content',
                    'sitemap',
                    'lighthouse',
                ].includes(check.key)
                    ? { ...check, recommended: false }
                    : check,
            )}
        />,
    );

    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.queryByText('Checking site')).toBeNull());
    const recommended = within(
        screen.getByRole('region', { name: 'add_site.group.recommended' }),
    );
    for (const key of [
        'broken_links',
        'mixed_content',
        'sitemap',
        'lighthouse',
    ]) {
        expect(
            recommended.getByRole('switch', { name: `checks.${key}.label` }),
        ).not.toBeNull();
    }
    expect(
        recommended
            .getByRole('switch', { name: 'checks.lighthouse.label' })
            .getAttribute('aria-checked'),
    ).toBe('false');
    expect(
        recommended
            .getByRole('switch', { name: 'checks.mixed_content.label' })
            .getAttribute('aria-checked'),
    ).toBe('false');
    expect(
        screen
            .getByRole('switch', { name: 'checks.application_health.label' })
            .getAttribute('aria-checked'),
    ).toBe('true');
    expect(stored().checks.toSorted()).toEqual([
        'application_health',
        'broken_links',
        'sitemap',
        'uptime',
    ]);
});
