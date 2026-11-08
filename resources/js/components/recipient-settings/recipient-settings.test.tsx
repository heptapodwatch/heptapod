import { http, HttpResponseError } from '@inertiajs/core';
import type { HttpRequestConfig, HttpResponse } from '@inertiajs/core';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type {
    Channel,
    SettingsPage,
} from '@/components/recipient-settings/model';
import { RecipientSettings } from '@/components/recipient-settings/recipient-settings';

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});

const requests: { method: string; url: string; body: unknown }[] = [];
/** What the server answers a write: 200, or a 422 refusal with its field messages. */
let writeResponse: HttpResponse;
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        props: { auth: { canManageInstance: true }, timezone: 'UTC' },
    }),
    router: { on: () => () => {}, reload: vi.fn() },
}));
/** The real Inertia useHttp over a fake transport: only the server's answer is faked. */
function fakeTransport(config: HttpRequestConfig): Promise<HttpResponse> {
    if (config.method === 'get') return new Promise(() => {});
    requests.push({
        method: config.method,
        url: config.url,
        body: JSON.parse(String(config.data)),
    });
    return writeResponse.status >= 400
        ? Promise.reject(
              new HttpResponseError('refused', writeResponse, config.url),
          )
        : Promise.resolve(writeResponse);
}
const json = (status: number, body: unknown): HttpResponse => ({
    status,
    data: JSON.stringify(body),
    headers: {},
});
vi.mock('react-i18next', () => {
    // Keys with their interpolation values, so assertions read the composed sentence.
    const t = (key: string, values?: Record<string, unknown>) =>
        values
            ? `${key}(${Object.entries(values)
                  .map(([name, value]) => `${name}=${String(value)}`)
                  .join(',')})`
            : key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
const toast = vi.hoisted(() =>
    Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
);
vi.mock('sonner', () => ({ toast }));

beforeEach(() => {
    requests.length = 0;
    writeResponse = json(200, { id: 1, enabled: true, suppressed_count: 0 });
    http.setClient({ request: fakeTransport });
    toast.mockClear();
    toast.success.mockClear();
    toast.error.mockClear();
    window.history.replaceState(null, '', '/settings/notifications/team');
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const now = 'immediate';
const grid: SettingsPage['grid'] = [
    { category: 'outage', modes: ['immediate', 'off'] },
    {
        category: 'warning',
        modes: ['immediate', 'digest', 'off'],
    },
    { category: 'system', modes: ['immediate', 'off'] },
];

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 7,
        channel: 'slack',
        label: 'Slack #alerts',
        address: null,
        status: 'active',
        account_mail: false,
        locale: 'en',
        scope_client_id: null,
        attention_reason: null,
        last_outcome: null,
        last_outcome_diagnosis: null,
        last_outcome_at: null,
        queued_count: 0,
        config: { channel: '#alerts' },
        preferences: { outage: now, warning: 'digest', system: now },
        // Started without System; the Owner's lock turned it on, and a reset must leave a locked cell alone.
        defaults: { outage: now, warning: now, system: 'off' },
        system_locked: true,
        system_bypasses_pause: false,
        muted_site_ids: [3],
        can_remove: true,
        ...overrides,
    };
}

function page(channels: Channel[]): SettingsPage {
    return {
        recipient_kind: 'team',
        recipient_id: null,
        recipient_name: 'Studio',
        channels,
        grid,
        schedule: {
            quiet_start: null,
            quiet_end: null,
            quiet_weekends: false,
            digest_time: '08:00',
            digest_weekdays: null,
            timezone: 'Europe/Berlin',
        },
        sites: [
            {
                id: 3,
                name: 'staging.example',
                identity: {
                    label: 'staging.example',
                    detail: 'Studio · staging.example',
                },
                client_id: 1,
                client_name: 'Studio',
                host: 'staging.example',
                url: 'https://staging.example',
                icon_url: null,
                icon_backing: 'none',
            },
        ],
        clients: [],
        channel_types: [],
        migration_report: null,
    };
}

const show = (channels: Channel[]) =>
    render(
        <RecipientSettings page={page(channels)} scopeDescription="scope" />,
    );

it('summarises each channel from its grid and marks a changed one Custom', () => {
    show([channel()]);
    const row = screen.getByText('Slack #alerts').closest('[data-channel]')!;
    // Immediately and digest categories, the digest time and the left-out site, in one sentence.
    expect(row.textContent).toContain(
        'recipient_settings.sentence.now_and_digest(now=recipient_settings.category.outage.plural recipient_settings.and recipient_settings.category.system.plural,digest=recipient_settings.category.warning.plural,time=08:00)',
    );
    expect(row.textContent).toContain(
        'recipient_settings.sentence.not_for(sites=staging.example)',
    );
    expect(within(row as HTMLElement).getByText('recipient_settings.custom'));
});

it('shades changed rows, resets them to how the channel started and saves only changed unlocked cells', async () => {
    window.history.replaceState(null, '', '?channel=7');
    show([channel()]);
    const sheet = await screen.findByRole('dialog');
    const warning = sheet.querySelector('[data-category="warning"]')!;
    expect(warning.hasAttribute('data-differs')).toBe(true);
    expect(warning.textContent).toContain('default=Immediately');
    expect(
        sheet
            .querySelector('[data-category="outage"]')!
            .hasAttribute('data-differs'),
    ).toBe(false);

    fireEvent.click(
        within(sheet).getByRole('button', {
            name: 'recipient_settings.reset_defaults',
        }),
    );
    expect(warning.hasAttribute('data-differs')).toBe(false);
    fireEvent.click(within(sheet).getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(requests).toHaveLength(1));
    // The locked System cell is never sent, and the unchanged Outage cell is left alone.
    expect(requests[0]).toEqual({
        method: 'put',
        url: '/notification-channels/7/preferences',
        body: { preferences: { warning: now } },
    });
});

it('marks nothing as changed when a channel has no known starting grid', async () => {
    window.history.replaceState(null, '', '?channel=7');
    show([channel({ defaults: null })]);
    expect(screen.queryByText('recipient_settings.custom')).toBeNull();
    const sheet = await screen.findByRole('dialog');
    expect(sheet.querySelector('[data-differs]')).toBeNull();
    expect(
        within(sheet).queryByRole('button', {
            name: 'recipient_settings.reset_defaults',
        }),
    ).toBeNull();
    expect(sheet.textContent).toContain('recipient_settings.grid.quiet_note');
});

it('keeps the schedule unsaveable while a typed time is not a time, and says why', () => {
    show([channel()]);
    const save = () =>
        screen
            .getAllByRole('button', { name: 'Save' })
            .at(-1) as HTMLButtonElement;
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'recipient_settings.schedule.weekends',
        }),
    );
    expect(save().disabled).toBe(false);

    const quietFrom = screen.getByLabelText(
        'recipient_settings.schedule.quiet_from',
    );
    fireEvent.change(quietFrom, { target: { value: '2561' } });
    expect(save().disabled).toBe(true);
    expect(quietFrom.getAttribute('aria-describedby')).toBeTruthy();
    expect(
        document.getElementById(quietFrom.getAttribute('aria-describedby')!)
            ?.textContent,
    ).toBe('Enter the time in 24-hour format (HH:MM).');
    fireEvent.click(save());
    expect(requests).toHaveLength(0);

    fireEvent.change(quietFrom, { target: { value: '2230' } });
    expect(save().disabled).toBe(false);
});

it('says a paused channel still gets system issues when they bypass the pause, and nothing otherwise', async () => {
    window.history.replaceState(null, '', '?channel=7');
    show([
        channel({ status: 'paused', system_bypasses_pause: true }),
        channel({ id: 8, label: 'Ops hook', status: 'paused' }),
    ]);
    const row = (label: string) =>
        screen.getByText(label).closest('[data-channel]')!.textContent;
    expect(row('Ops hook')).toContain('recipient_settings.paused_prefix ');
    const sheet = await screen.findByRole('dialog');
    expect(sheet.textContent).toContain(
        'recipient_settings.paused_notice_system',
    );
    expect(
        screen.getAllByText('Slack #alerts')[0]!.closest('[data-channel]')!
            .textContent,
    ).toContain('recipient_settings.paused_prefix_system');
});

it('shows a refused schedule save at its field and never says it was saved', async () => {
    writeResponse = json(422, {
        message: 'Choose at least one day for the digest.',
        errors: {
            digest_weekdays: ['Choose at least one day for the digest.'],
        },
    });
    show([channel()]);
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'recipient_settings.schedule.weekends',
        }),
    );
    fireEvent.click(screen.getAllByRole('button', { name: 'Save' }).at(-1)!);
    expect(
        await screen.findByText('Choose at least one day for the digest.'),
    ).toBeTruthy();
    expect(requests).toHaveLength(1);
    expect(toast.success).not.toHaveBeenCalled();
    expect(screen.queryByText('recipient_settings.save_failed')).toBeNull();
});

it('keeps the channel sheet open with the reason when the server refuses its change', async () => {
    writeResponse = json(422, {
        message: 'This channel cannot receive warnings as a digest.',
        errors: {
            'preferences.warning': [
                'This channel cannot receive warnings as a digest.',
            ],
        },
    });
    window.history.replaceState(null, '', '?channel=7');
    show([channel()]);
    const sheet = await screen.findByRole('dialog');
    fireEvent.click(
        within(sheet).getByRole('button', {
            name: 'recipient_settings.reset_defaults',
        }),
    );
    fireEvent.click(within(sheet).getByRole('button', { name: 'Save' }));
    expect(
        await within(sheet).findByText(
            'This channel cannot receive warnings as a digest.',
        ),
    ).toBeTruthy();
    expect(toast.success).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog')).toBe(sheet);
});

it("shows the provider's reason in the sheet when a test is rejected", async () => {
    writeResponse = json(200, {
        status: 'failed',
        reason: 'notifications.transport_failed',
        providerMessageId: null,
        diagnosis: 'HTTP 200 — not_in_channel',
    });
    window.history.replaceState(null, '', '?channel=7');
    show([channel()]);
    const sheet = await screen.findByRole('dialog');
    fireEvent.click(within(sheet).getByRole('button', { name: 'Send test' }));
    expect((await within(sheet).findByRole('alert')).textContent).toBe(
        'HTTP 200 — not_in_channel',
    );
    expect(toast.error).toHaveBeenCalledWith('notifications.transport_failed');
});

it('shows a refused channel setting at its field, under its label, and never says the channel was added', async () => {
    writeResponse = json(422, {
        message: 'The Email address field must be a valid email address.',
        errors: {
            'values.email': [
                'The Email address field must be a valid email address.',
            ],
        },
    });
    render(
        <RecipientSettings
            page={{
                ...page([]),
                channel_types: [
                    {
                        key: 'mail',
                        label_key: 'notifications.channel.mail',
                        description_key:
                            'notifications.channel.mail.description',
                        config_schema: {
                            fields: {
                                email: {
                                    type: 'string',
                                    rules: ['required', 'email'],
                                    properties: {},
                                    items: null,
                                    presentation: {
                                        labelKey: 'channels.field.email',
                                        helpKey: null,
                                        options: {},
                                    },
                                    additionalProperties: false,
                                    targetUrl: false,
                                    uppercase: false,
                                    trimmedStrings: false,
                                },
                            },
                        },
                    },
                ],
            }}
            scopeDescription="scope"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add channel' }));
    const sheet = await screen.findByRole('dialog');
    const email = within(sheet).getByLabelText(/channels\.field\.email/);
    fireEvent.change(email, { target: { value: 'not an email' } });
    fireEvent.click(within(sheet).getByRole('button', { name: 'Add channel' }));
    await waitFor(() =>
        expect(email.getAttribute('aria-invalid')).toBe('true'),
    );
    const describedBy = email.getAttribute('aria-describedby')!.split(' ');
    expect(
        describedBy.map((id) => document.getElementById(id)?.textContent),
    ).toContain('The Email address field must be a valid email address.');
    expect(toast.success).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog')).toBe(sheet);
});

it('tells same-named sites apart in the exception picker, its chips and the channel sentence', async () => {
    const site = (id: number, url: string, client: string, label: string) => ({
        identity: { label, detail: `${client} · ${new URL(url).hostname}` },
        id,
        name: 'Website',
        client_id: id,
        client_name: client,
        host: new URL(url).hostname,
        url,
        icon_url: null,
        icon_backing: 'none' as const,
    });
    window.history.replaceState(null, '', '?channel=7');
    render(
        <RecipientSettings
            page={{
                ...page([channel({ muted_site_ids: [3] })]),
                sites: [
                    site(
                        3,
                        'https://shop.example',
                        'Kestrel',
                        'Website (Kestrel · shop.example)',
                    ),
                    site(
                        4,
                        'https://blog.example',
                        'Hollis',
                        'Website (blog.example)',
                    ),
                    site(
                        5,
                        'https://shop.example',
                        'Pike',
                        'Website (Pike · shop.example)',
                    ),
                ],
            }}
            scopeDescription="scope"
        />,
    );
    const sheet = await screen.findByRole('dialog');
    // The chip and the sentence name the muted site with its client and host, since name and host both collide.
    expect(within(sheet).getByRole('listitem').textContent).toBe(
        'Website (Kestrel · shop.example)',
    );
    expect(sheet.textContent).toContain(
        'recipient_settings.sentence.not_for(sites=Website (Kestrel · shop.example))',
    );
    fireEvent.click(
        within(sheet).getByRole('combobox', {
            name: 'recipient_settings.mutes.picker',
        }),
    );
    // List rows: name, then client · host.
    const blog = await screen.findByRole('option', {
        name: /Hollis · blog\.example/,
    });
    fireEvent.click(blog);
    expect(sheet.textContent).toContain(
        'recipient_settings.sentence.not_for(sites=Website (Kestrel · shop.example) recipient_settings.and Website (blog.example))',
    );
});

it('blocks saving a digest without a day and says so', () => {
    show([channel()]);
    for (const day of [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ])
        fireEvent.click(screen.getByRole('button', { name: day }));
    const save = screen
        .getAllByRole('button', { name: 'Save' })
        .at(-1) as HTMLButtonElement;
    expect(save.disabled).toBe(true);
    expect(
        screen.getByText('recipient_settings.schedule.no_days'),
    ).toBeTruthy();
    fireEvent.click(save);
    expect(requests).toHaveLength(0);
});

it('tells apart same-named sites of one client and host by path, then port, then ID', async () => {
    const site = (id: number, url: string, detail: string) => ({
        identity: { label: `Website (${detail})`, detail },
        id,
        name: 'Website',
        client_id: 1,
        client_name: 'Kestrel',
        host: new URL(url).hostname,
        url,
        icon_url: null,
        icon_backing: 'none' as const,
    });
    window.history.replaceState(null, '', '?channel=7');
    render(
        <RecipientSettings
            page={{
                ...page([channel({ muted_site_ids: [10, 11, 12, 13, 14] })]),
                sites: [
                    site(
                        10,
                        'https://example.test/shop',
                        'Kestrel · example.test/shop',
                    ),
                    site(
                        11,
                        'https://example.test/blog',
                        'Kestrel · example.test/blog',
                    ),
                    site(
                        12,
                        'https://example.test:8443/blog',
                        'Kestrel · example.test:8443/blog',
                    ),
                    site(
                        13,
                        'http://example.test/docs',
                        'Kestrel · example.test/docs · #13',
                    ),
                    site(
                        14,
                        'https://example.test/docs',
                        'Kestrel · example.test/docs · #14',
                    ),
                ],
            }}
            scopeDescription="scope"
        />,
    );
    const sheet = await screen.findByRole('dialog');
    const chips = within(sheet)
        .getAllByRole('listitem')
        .map((chip) => chip.textContent);
    expect(chips).toEqual([
        'Website (Kestrel · example.test/shop)',
        'Website (Kestrel · example.test/blog)',
        'Website (Kestrel · example.test:8443/blog)',
        'Website (Kestrel · example.test/docs · #13)',
        'Website (Kestrel · example.test/docs · #14)',
    ]);
    expect(new Set(chips).size).toBe(chips.length);
    fireEvent.click(
        within(sheet).getByRole('combobox', {
            name: 'recipient_settings.mutes.picker',
        }),
    );
    // Picker rows carry the same deciding detail under the name.
    const rows = (await screen.findAllByRole('option')).map(
        (option) => option.textContent,
    );
    expect(new Set(rows).size).toBe(rows.length);
    expect(rows).toContain('WWebsiteKestrel · example.test:8443/blog');
});

it('uses server identities for mixed-stage collisions in chips and picker details', async () => {
    window.history.replaceState(null, '', '?channel=7');
    const sites = [
        {
            id: 10,
            name: 'A',
            host: 'foo.test',
            label: 'A (Kestrel · foo.test)',
            detail: 'Kestrel · foo.test',
        },
        {
            id: 11,
            name: 'A',
            host: 'bar.test',
            label: 'A (bar.test)',
            detail: 'Kestrel · bar.test',
        },
        {
            id: 12,
            name: 'A (foo.test)',
            host: 'literal.test',
            label: 'A (foo.test) (literal.test)',
            detail: 'Kestrel · literal.test',
        },
    ].map(({ label, detail, ...site }) => ({
        ...site,
        client_id: 1,
        client_name: 'Kestrel',
        url: `https://${site.host}`,
        icon_url: null,
        icon_backing: 'none' as const,
        identity: { label, detail },
    }));
    render(
        <RecipientSettings
            page={{ ...page([channel({ muted_site_ids: [10, 12] })]), sites }}
            scopeDescription="scope"
        />,
    );
    const sheet = await screen.findByRole('dialog');
    expect(
        within(sheet)
            .getAllByRole('listitem')
            .map((chip) => chip.textContent),
    ).toEqual(['A (Kestrel · foo.test)', 'A (foo.test) (literal.test)']);
    fireEvent.click(
        within(sheet).getByRole('combobox', {
            name: 'recipient_settings.mutes.picker',
        }),
    );
    expect(
        await screen.findByRole('option', { name: /Kestrel · literal\.test/ }),
    ).toBeTruthy();
});
