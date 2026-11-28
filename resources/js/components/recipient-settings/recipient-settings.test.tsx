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
import { stubPickerFetch } from '@/test/picker-fetch';

type MutedSite = SettingsPage['muted_sites'][number];
/** The same site as the exceptions picker finds it on the server. */
/** The same site as the exceptions picker finds it: the server names it apart (SiteLabels) within its answer. */
const pickerSite = ({
    identity,
    url: _url,
    ...site
}: MutedSite): Heptapod.Data.Ui.PickerSiteData => ({
    ...site,
    timezone: 'UTC',
    client_is_internal: false,
    label: identity.label,
    detail: identity.detail,
});
const staging: MutedSite = {
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
};

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
let mockedPageUrl: string | null = null;
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        url: mockedPageUrl ?? window.location.pathname + window.location.search,
        props: {
            auth: { canManageInstance: true },
            supportedLocales: [
                { code: 'en', name: 'English' },
                { code: 'de', name: 'Deutsch' },
            ],
            timezone: 'UTC',
        },
    }),
    router: { on: () => () => {}, reload: vi.fn(), flushAll: vi.fn() },
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
    stubPickerFetch({ sites: [pickerSite(staging)] });
    requests.length = 0;
    mockedPageUrl = null;
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
        timezone: null,
        scope_client_id: null,
        check_types: null,
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
        muted_sites: [staging],
        site_count: 1,
        channel_types: [],
        check_types: [],
        timezones: ['UTC', 'Europe/Berlin'],
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
        method: 'patch',
        url: '/notification-channels/7',
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

const mailChannelType: SettingsPage['channel_types'][number] = {
    key: 'mail',
    label_key: 'notifications.channel.mail',
    description_key: 'notifications.channel.mail.description',
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
};

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
                channel_types: [mailChannelType],
            }}
            scopeDescription="scope"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add channel' }));
    const sheet = await screen.findByRole('dialog');
    const email = within(sheet).getByLabelText(/channels\.field\.email/);
    fireEvent.change(within(sheet).getByLabelText('Name'), {
        target: { value: 'Alerts' },
    });
    fireEvent.change(email, { target: { value: 'alerts@example.test' } });
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

it('reopens a collapsed connection and associates the save error with its field', async () => {
    const message = 'This address is not available.';
    writeResponse = json(422, { errors: { 'values.email': [message] } });
    render(
        <RecipientSettings
            page={{
                ...page([
                    channel({
                        channel: 'mail',
                        label: 'Email alerts',
                        config: { email: 'saved@example.test' },
                    }),
                ]),
                channel_types: [mailChannelType],
            }}
            scopeDescription="scope"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Email alerts' }));
    const sheet = await screen.findByRole('dialog');
    const connectionMenu = () =>
        fireEvent.keyDown(
            within(sheet).getByRole('button', { name: 'More actions' }),
            { key: 'Enter' },
        );
    connectionMenu();
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Edit' }));
    fireEvent.change(
        within(sheet).getByRole('textbox', { name: /channels\.field\.email/ }),
        { target: { value: 'draft@example.test' } },
    );
    connectionMenu();
    fireEvent.click(
        await screen.findByRole('menuitem', {
            name: 'recipient_settings.form.collapse',
        }),
    );
    expect(within(sheet).queryByRole('textbox')).toBeNull();

    fireEvent.click(within(sheet).getByRole('button', { name: 'Save' }));

    const email = await within(sheet).findByRole('textbox', {
        name: /channels\.field\.email/,
    });
    expect((email as HTMLInputElement).value).toBe('draft@example.test');
    expect(email.getAttribute('aria-invalid')).toBe('true');
    expect(
        email
            .getAttribute('aria-describedby')
            ?.split(' ')
            .map((id) => document.getElementById(id)?.textContent),
    ).toContain(message);
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
    const kestrel = site(
        3,
        'https://shop.example',
        'Kestrel',
        'Website (Kestrel · shop.example)',
    );
    stubPickerFetch({
        sites: [
            kestrel,
            site(4, 'https://blog.example', 'Hollis', 'Website (blog.example)'),
            site(
                5,
                'https://shop.example',
                'Pike',
                'Website (Pike · shop.example)',
            ),
        ].map(pickerSite),
    });
    render(
        <RecipientSettings
            page={{
                ...page([channel({ muted_site_ids: [3] })]),
                muted_sites: [kestrel],
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
        'recipient_settings.sentence.not_for(sites=Website (Kestrel · shop.example) recipient_settings.and Website (Hollis · blog.example))',
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
    const muted = [
        site(10, 'https://example.test/shop', 'Kestrel · example.test/shop'),
        site(11, 'https://example.test/blog', 'Kestrel · example.test/blog'),
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
    ];
    stubPickerFetch({ sites: muted.map(pickerSite) });
    window.history.replaceState(null, '', '?channel=7');
    render(
        <RecipientSettings
            page={{
                ...page([channel({ muted_site_ids: [10, 11, 12, 13, 14] })]),
                muted_sites: muted,
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
    stubPickerFetch({ sites: sites.map(pickerSite) });
    render(
        <RecipientSettings
            page={{
                ...page([channel({ muted_site_ids: [10, 12] })]),
                muted_sites: sites.filter(({ id }) => id !== 11),
            }}
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

it('saves channel preference and site-mute changes in a single atomic request', async () => {
    stubPickerFetch({
        sites: [
            pickerSite(staging),
            pickerSite({
                ...staging,
                id: 4,
                name: 'shop.example',
                host: 'shop.example',
                identity: {
                    label: 'shop.example',
                    detail: 'Studio · shop.example',
                },
            }),
        ],
    });
    render(
        <RecipientSettings
            page={page([channel({ muted_site_ids: [3] })])}
            scopeDescription="scope"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Slack #alerts' }));
    const sheet = await screen.findByRole('dialog');
    fireEvent.click(
        within(sheet).getByRole('button', {
            name: 'recipient_settings.reset_defaults',
        }),
    );
    fireEvent.click(
        within(sheet).getByRole('combobox', {
            name: 'recipient_settings.mutes.picker',
        }),
    );
    fireEvent.click(
        await screen.findByRole('option', { name: /shop\.example/ }),
    );
    fireEvent.click(
        await screen.findByRole('option', { name: /staging\.example/ }),
    );
    fireEvent.click(within(sheet).getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(requests).toHaveLength(1));
    expect(requests[0]).toEqual({
        method: 'patch',
        url: '/notification-channels/7',
        body: {
            preferences: { warning: now },
            mute_site_ids: [4],
            unmute_site_ids: [3],
        },
    });
});

it('reads ?channel= from the Inertia page URL rather than window.location.search', async () => {
    window.history.replaceState(null, '', '/settings/notifications/team');
    mockedPageUrl = '/settings/notifications/team?channel=7';
    show([channel()]);
    expect(await screen.findByRole('dialog')).toBeTruthy();
});

it('clears invalid schedule time drafts and unblocks saving on Discard', () => {
    show([channel()]);
    const quietFrom = () =>
        screen.getByLabelText(
            'recipient_settings.schedule.quiet_from',
        ) as HTMLInputElement;
    const discard = () =>
        screen.getByRole('button', { name: 'Discard' }) as HTMLButtonElement;
    const save = () =>
        screen
            .getAllByRole('button', { name: 'Save' })
            .at(-1) as HTMLButtonElement;

    fireEvent.change(quietFrom(), { target: { value: '2561' } });
    fireEvent.blur(quietFrom());
    expect(quietFrom().getAttribute('aria-invalid')).toBe('true');
    expect(discard().disabled).toBe(false);
    expect(save().disabled).toBe(true);

    fireEvent.click(discard());
    expect(quietFrom().value).toBe('');
    expect(quietFrom().getAttribute('aria-invalid')).toBeNull();
    expect(
        screen.queryByText('Enter the time in 24-hour format (HH:MM).'),
    ).toBeNull();
    expect(discard().disabled).toBe(true);

    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'recipient_settings.schedule.weekends',
        }),
    );
    expect(save().disabled).toBe(false);
});
