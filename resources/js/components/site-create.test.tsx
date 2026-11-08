import {
    act,
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
const urlField = () => screen.getByLabelText('URL') as HTMLInputElement;
const clientField = () => screen.queryByRole('combobox', { name: 'Client' });
/** Enters an address and leaves the field, as a user moving on; resolves once the probe revealed Client. */
const enterAddress = async (url = 'https://shop.example.test') => {
    fireEvent.change(urlField(), { target: { value: url } });
    fireEvent.blur(urlField());
    await waitFor(() => expect(clientField()).not.toBeNull());
};
const chooseClient = (name = options.clients[1]!.name) => {
    fireEvent.click(clientField()!);
    fireEvent.click(screen.getByRole('option', { name }));
};
const restoreDraft = (draft: Record<string, unknown>) =>
    localStorage.setItem(
        storageKey(),
        JSON.stringify({
            url: 'https://shop.example.test',
            client_id: String(options.clients[0]!.id),
            ...draft,
        }),
    );
const switchState = (name: string) =>
    screen.getByRole('switch', { name }).getAttribute('aria-checked');

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
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('reveals Client once the address is checked, then Details and Checks once a client is chosen, and never collapses them', async () => {
    render(<AddSite {...options} />);
    expect(clientField()).toBeNull();
    await enterAddress();
    expect(
        within(clientField()!).queryByText(options.clients[0]!.name),
    ).toBeNull();
    expect(screen.queryByLabelText('Name')).toBeNull();
    chooseClient();
    expect(screen.getByLabelText('Name')).not.toBeNull();
    expect(screen.getByLabelText('Tags')).not.toBeNull();
    expect(switchState('checks.uptime.label')).toBe('true');
    fireEvent.change(urlField(), { target: { value: '' } });
    expect(clientField()).not.toBeNull();
    expect(screen.getByLabelText('Name')).not.toBeNull();
});

it('reveals Client after an unreachable address but not after an address the server refuses', async () => {
    vi.mocked(fetch).mockResolvedValueOnce({
        ok: false,
        status: 422,
        json: async () => ({
            errors: { url: ['Enter an HTTP or HTTPS address.'] },
        }),
    } as Response);
    render(<AddSite {...options} />);
    fireEvent.change(urlField(), { target: { value: 'ftp://shop.example' } });
    fireEvent.blur(urlField());
    await waitFor(() =>
        expect(
            screen.getByText('Enter an HTTP or HTTPS address.'),
        ).not.toBeNull(),
    );
    expect(screen.queryByText(/sites.url_unreachable/)).toBeNull();
    expect(clientField()).toBeNull();
    vi.mocked(fetch).mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            recommended_checks: ['uptime'],
            failure: 'connection_refused',
            failure_reason: 'Connection refused',
            status: null,
        }),
    } as Response);
    await enterAddress('https://down.example');
    expect(
        screen.getByText('sites.url_unreachable · Connection refused'),
    ).not.toBeNull();
});

it('preselects the only client and reveals everything after the address check', async () => {
    render(<AddSite {...options} clients={[options.clients[1]!]} />);
    await enterAddress();
    expect(
        within(clientField()!).getByText(options.clients[1]!.name),
    ).not.toBeNull();
    expect(screen.getByLabelText('Name')).not.toBeNull();
});

it('moves focus into a revealed section only after a keyboard finish, never while typing', async () => {
    vi.useFakeTimers();
    render(<AddSite {...options} />);
    fireEvent.keyDown(urlField(), { key: 's' });
    fireEvent.change(urlField(), {
        target: { value: 'https://shop.example.test' },
    });
    await act(() => vi.advanceTimersByTimeAsync(700));
    expect(clientField()).not.toBeNull();
    await act(() => vi.advanceTimersByTimeAsync(50));
    expect(document.activeElement).toBe(urlField());
    cleanup();
    localStorage.clear();
    render(<AddSite {...options} />);
    fireEvent.change(urlField(), {
        target: { value: 'https://shop.example.test' },
    });
    fireEvent.keyDown(urlField(), { key: 'Enter' });
    // The probe answers, then the next animation frame moves focus.
    await act(() => vi.advanceTimersByTimeAsync(0));
    expect(clientField()).not.toBeNull();
    await act(() => vi.advanceTimersByTimeAsync(50));
    expect(document.activeElement).toBe(clientField());
});

it('validates every field at once, reveals every section and focuses the first invalid field', async () => {
    vi.useFakeTimers();
    render(<AddSite {...options} />);
    fireEvent.keyDown(screen.getByRole('button', { name: 'Add site' }), {
        key: 'Enter',
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
    await act(() => vi.advanceTimersByTimeAsync(50));
    expect(
        screen.getByText(
            'Enter a valid HTTP or HTTPS URL without credentials.',
        ),
    ).not.toBeNull();
    expect(screen.getByText('Select a client.')).not.toBeNull();
    expect(screen.getByLabelText('Name')).not.toBeNull();
    expect(screen.getByText('add_site.summary.attention')).not.toBeNull();
    expect(document.activeElement).toBe(urlField());
    fireEvent.change(urlField(), {
        target: { value: 'https://shop.example.test' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
    await act(() => vi.advanceTimersByTimeAsync(50));
    expect(document.activeElement).toBe(clientField());
});

it('submits name, distinct tags and the selected checks once', async () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    render(<AddSite {...options} />);
    await enterAddress();
    chooseClient();
    fireEvent.change(screen.getByLabelText('Name'), {
        target: { value: ' Shop ' },
    });
    fireEvent.change(screen.getByLabelText('Tags'), {
        target: { value: 'shop, live,shop' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0]![1]).toEqual({
        url: 'https://shop.example.test',
        client_id: String(options.clients[1]!.id),
        friendly_name: 'Shop',
        tags: ['shop', 'live'],
        checks: ['uptime'],
    });
});

it.each(['shop.example.test', 'https://shop.example.test'])(
    'restores the ordinary host %s without an omitted-secrets note',
    (url) => {
        const view = render(<AddSite {...options} />);
        fireEvent.change(urlField(), { target: { value: url } });
        view.unmount();
        render(<AddSite {...options} />);
        expect(urlField().value).toBe('https://shop.example.test/');
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
        fireEvent.change(urlField(), { target: { value: url } });
        view.unmount();
        render(<AddSite {...options} />);
        expect(urlField().value).toBe('https://shop.example.test/');
        expect(screen.getByText('sites.draft.secrets_omitted')).not.toBeNull();
        expect(stored().omitted).toBe(true);
    },
);

it('restores the whole draft with its sections, manual check choices and settings after a reload', async () => {
    const view = render(<AddSite {...options} />);
    await enterAddress();
    chooseClient();
    fireEvent.change(screen.getByLabelText('Name'), {
        target: { value: 'Shop' },
    });
    fireEvent.change(screen.getByLabelText('Tags'), {
        target: { value: 'shop, live' },
    });
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
    view.unmount();
    render(<AddSite {...options} />);
    expect(
        within(clientField()!).getByText(options.clients[1]!.name),
    ).not.toBeNull();
    expect((screen.getByLabelText('Name') as HTMLInputElement).value).toBe(
        'Shop',
    );
    expect((screen.getByLabelText('Tags') as HTMLInputElement).value).toBe(
        'shop, live',
    );
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
    expect(switchState('checks.dns.label')).toBe('true');
    expect(stored().changedChecks).toContain('dns');
    expect(stored().settings['uptime.timeout']).toBe(17);
    expect(stored()).not.toHaveProperty('step');
});

it('never stores URL credentials, query, fragment, health secrets, request headers or request bodies', () => {
    const view = render(<AddSite {...options} />);
    fireEvent.change(urlField(), {
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
    restoreDraft({
        url: 'https://user:password@shop.example.test/path?token=private#private',
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
    });
    render(<AddSite {...withNested} />);
    const draft = localStorage.getItem(storageKey())!;
    expect(draft).not.toMatch(
        /password|token=private|#private|health-private|header-private|body-private|nested-private/,
    );
    expect(stored().settings).toEqual({
        'uptime.timeout': 12,
        'uptime.nested': { label: 'public' },
    });
    expect(urlField().value).toBe('https://shop.example.test/path');
    expect(screen.getByText('sites.draft.secrets_omitted')).not.toBeNull();
});

it('isolates drafts per user, rejects malformed storage and clamps removed clients and unavailable checks', () => {
    localStorage.setItem(storageKey(), 'not json');
    const view = render(<AddSite {...options} />);
    expect(urlField().value).toBe('');
    fireEvent.change(urlField(), {
        target: { value: 'https://first.example.test' },
    });
    view.unmount();
    userId = 2;
    restoreDraft({
        url: 'https://second.example.test',
        client_id: '-123',
        checks: ['uptime', 'lighthouse', 'not-installed'],
    });
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
    expect(stored().client_id).toBe('');
    expect(stored().checks).toEqual(['uptime']);
    expect(clientField()).not.toBeNull();
    expect(screen.queryByLabelText('Name')).toBeNull();
    expect(JSON.parse(localStorage.getItem(storageKey(1))!).url).toBe(
        'https://first.example.test/',
    );
});

it('clears the draft only on explicit discard or successful save, not validation failure', async () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    let view = render(<AddSite {...options} />);
    fireEvent.change(urlField(), {
        target: { value: 'https://shop.example.test' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Discard' }));
    expect(localStorage.getItem(storageKey())).toBeNull();
    expect(visit).toHaveBeenCalled();
    view.unmount();
    restoreDraft({ checks: ['uptime'] });
    view = render(<AddSite {...options} />);
    const post = vi
        .spyOn(router, 'post')
        .mockImplementation((_url, _data, options) => {
            options?.onError?.({ url: 'URL already exists' });
        });
    fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
    expect(screen.getByText('URL already exists')).not.toBeNull();
    expect(localStorage.getItem(storageKey())).not.toBeNull();
    post.mockImplementation((_url, _data, options) => {
        options?.onSuccess?.(
            {} as Parameters<
                NonNullable<NonNullable<typeof options>['onSuccess']>
            >[0],
        );
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
    await waitFor(() => expect(localStorage.getItem(storageKey())).toBeNull());
});

it('creates a client in a sheet with the normal form and selects it in place', async () => {
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
    await enterAddress();
    fireEvent.click(clientField()!);
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
    expect(within(clientField()!).getByText('New Studio')).not.toBeNull();
    expect(stored().client_id).toBe('987');
    expect(screen.getByLabelText('Name')).not.toBeNull();
});

it('cancels client creation without changing the selected client and gives Members no Create entry', async () => {
    const view = render(<AddSite {...options} />);
    await enterAddress();
    fireEvent.click(clientField()!);
    fireEvent.change(
        screen.getByRole('combobox', { name: 'combobox.search' }),
        { target: { value: 'Cancelled Studio' } },
    );
    fireEvent.click(
        screen.getByRole('option', { name: 'clients.create_inline' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(stored().client_id).toBe('');
    view.unmount();
    render(<AddSite {...options} can_create_site={false} />);
    fireEvent.click(clientField()!);
    expect(screen.queryByRole('option', { name: /Create/ })).toBeNull();
    expect(screen.queryByText('clients.create_inline')).toBeNull();
});

it('groups checks by consequence, removes unavailable controls and moves Lighthouse into recommendations when enabled on the instance', () => {
    restoreDraft({ checks: ['uptime', 'performance'] });
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
    restoreDraft({ url: 'http://shop.example.test', checks: ['uptime'] });
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
    restoreDraft({ checks: ['uptime'] });
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
        target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    expect(screen.queryByText('add_site.settings.changed')).toBeNull();
});

it('opens the setup sheet when a check that needs settings is turned on and leaves it off when cancelled', () => {
    restoreDraft({ checks: ['uptime'] });
    render(<AddSite {...options} />);
    fireEvent.click(
        screen.getByRole('switch', { name: 'checks.application_health.label' }),
    );
    const sheet = screen.getByRole('dialog');
    expect(within(sheet).getByText('checks.application_health.label'));
    fireEvent.click(within(sheet).getByRole('button', { name: 'Cancel' }));
    expect(switchState('checks.application_health.label')).toBe('false');
});

it('names the probe reason on recommended checks and refreshes recommendations on restore without overriding explicit choices', async () => {
    restoreDraft({
        checks: ['uptime', 'application_health'],
        changedChecks: ['lighthouse', 'mixed_content', 'application_health'],
    });
    vi.mocked(fetch).mockResolvedValue({
        ok: true,
        json: async () => ({
            recommended_checks: [
                'uptime',
                'certificate_health',
                'broken_links',
                'mixed_content',
                'sitemap',
                'lighthouse',
            ],
            failure: null,
            status: 200,
            certificate_issuer: 'Example CA',
            sitemap_url: 'https://shop.example.test/sitemap.xml',
            public_html: true,
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
    await waitFor(() =>
        expect(screen.getByText('add_site.reason.status')).not.toBeNull(),
    );
    expect(fetch).toHaveBeenCalledTimes(1);
    const recommended = within(
        screen.getByRole('region', { name: 'add_site.group.recommended' }),
    );
    for (const key of [
        'broken_links',
        'mixed_content',
        'sitemap',
        'lighthouse',
    ])
        expect(
            recommended.getByRole('switch', { name: `checks.${key}.label` }),
        ).not.toBeNull();
    expect(recommended.getByText('add_site.reason.certificate')).not.toBeNull();
    expect(recommended.getByText('add_site.reason.sitemap')).not.toBeNull();
    expect(
        recommended.getAllByText('add_site.reason.public_html'),
    ).toHaveLength(3);
    expect(switchState('checks.lighthouse.label')).toBe('false');
    expect(switchState('checks.mixed_content.label')).toBe('false');
    expect(switchState('checks.application_health.label')).toBe('true');
    expect(stored().checks.toSorted()).toEqual([
        'application_health',
        'broken_links',
        'certificate_health',
        'sitemap',
        'uptime',
    ]);
});

it('debounces the address check, aborts stale results and normalises on blur', async () => {
    vi.useFakeTimers();
    const pending: ((value: Response) => void)[] = [];
    vi.mocked(fetch).mockImplementation(
        () =>
            new Promise((resolve) =>
                pending.push(resolve),
            ) as Promise<Response>,
    );
    render(<AddSite {...options} />);
    const field = urlField();
    fireEvent.change(field, { target: { value: 'FIRST.example/' } });
    await act(() => vi.advanceTimersByTimeAsync(599));
    expect(fetch).not.toHaveBeenCalled();
    await act(() => vi.advanceTimersByTimeAsync(1));
    expect(screen.getByText('sites.url_checking')).not.toBeNull();
    const firstSignal = vi.mocked(fetch).mock.calls[0]![1]!.signal!;
    fireEvent.change(field, { target: { value: 'SECOND.example/' } });
    expect(firstSignal.aborted).toBe(true);
    fireEvent.blur(field);
    const blurSignal = vi.mocked(fetch).mock.calls[1]![1]!.signal!;
    await act(() => vi.advanceTimersByTimeAsync(600));
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(blurSignal.aborted).toBe(false);
    const result = (url: string) =>
        ({
            ok: true,
            json: async () => ({
                normalised_url: url,
                final_url: url,
                status: 200,
                failure: null,
                recommended_checks: ['uptime'],
            }),
        }) as Response;
    await act(async () => pending[1]!(result('https://second.example')));
    expect(field.value).toBe('https://second.example');
    await act(async () => pending[0]!(result('https://first.example')));
    expect(field.value).toBe('https://second.example');
    expect(screen.queryByText('sites.url_checking')).toBeNull();
    expect(screen.getByText('sites.url_reachable · HTTP 200')).not.toBeNull();
    expect(stored().url).toBe('https://second.example/');
});

it('lets the server validate a scheme-less host with a port instead of rejecting it locally', async () => {
    render(<AddSite {...options} />);
    await enterAddress('shop.example:8080/path');
    expect(stored().url).toBe('https://shop.example:8080/path');
});

it('offers the observed redirect address and still reveals Client on an HTTP warning', async () => {
    vi.mocked(fetch).mockResolvedValue({
        ok: true,
        json: async () => ({
            normalised_url: 'http://shop.example',
            final_url: 'https://www.shop.example/store',
            status: 404,
            failure: null,
            recommended_checks: ['uptime'],
        }),
    } as Response);
    render(<AddSite {...options} />);
    await enterAddress('http://shop.example/');
    expect(screen.getByText('Warning · HTTP 404')).not.toBeNull();
    await act(async () => fireEvent.blur(urlField()));
    expect(fetch).toHaveBeenCalledTimes(1);
    fireEvent.click(
        screen.getByRole('button', { name: 'sites.url_use_address' }),
    );
    expect(urlField().value).toBe('https://www.shop.example/store');
    expect(clientField()).not.toBeNull();
});
