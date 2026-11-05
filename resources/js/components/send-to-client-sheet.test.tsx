import { http, HttpResponseError } from '@inertiajs/core';
import type { HttpRequestConfig, HttpResponse } from '@inertiajs/core';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { SendToClientSheet } from '@/components/send-to-client-sheet';

const requests: {
    url: string;
    body: unknown;
    headers: Record<string, string> | undefined;
}[] = [];
/** The send's answer: the server's 201, or its 422 refusal. */
let sendResponse: HttpResponse;
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { on: () => () => {}, visit: vi.fn() },
}));
/** The real Inertia useHttp over a fake transport: what the server answers is all that is faked. */
function fakeTransport(config: HttpRequestConfig): Promise<HttpResponse> {
    requests.push({
        url: config.url,
        body: JSON.parse(String(config.data)),
        headers: config.headers as Record<string, string> | undefined,
    });
    const response = config.url.endsWith('/preview')
        ? json(200, {
              subject: 'Defekte Links auf shop.test: 7 gefunden',
              html: '<p>Guten Tag,</p>',
              locale: 'de',
              total: 7,
          })
        : sendResponse;
    return response.status >= 400
        ? Promise.reject(new HttpResponseError('refused', response, config.url))
        : Promise.resolve(response);
}
const json = (status: number, body: unknown): HttpResponse => ({
    status,
    data: JSON.stringify(body),
    headers: {},
});
vi.mock('react-i18next', () => {
    const t = (key: string, values?: Record<string, unknown>) =>
        values ? `${key}(${JSON.stringify(values)})` : key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

const channel = (
    id: number,
    address: string,
    status: string,
    type = 'mail',
) => ({
    id,
    channel: type,
    label: `Label ${id}`,
    address: type === 'mail' ? address : null,
    status,
});

beforeEach(() => {
    requests.length = 0;
    sendResponse = json(201, {
        event_id: 5,
        delivery_ids: [8],
        replayed: false,
    });
    http.setClient({ request: fakeTransport });
    toast.success.mockClear();
    toast.error.mockClear();
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => ({
                channels: [
                    channel(1, 'office@client.test', 'active'),
                    channel(2, 'thomas@client.test', 'paused'),
                    channel(3, '', 'active', 'slack'),
                ],
            }),
        })),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('starts with the first email channel, warns about a paused one and sends once under one idempotency key', async () => {
    const onOpenChange = vi.fn();
    render(
        <SendToClientSheet
            open
            onOpenChange={onOpenChange}
            client={{ id: 9, name: 'Müller GmbH' }}
            subject={{ kind: 'findings', check_id: 4 }}
            initialDesign="branded"
        />,
    );
    const office = await screen.findByRole('checkbox', {
        name: /office@client\.test/,
    });
    expect(office.getAttribute('data-state')).toBe('checked');
    // Other channel types are listed but cannot receive a manual message.
    expect(
        screen
            .getByRole('checkbox', { name: /Label 3/ })
            .hasAttribute('disabled'),
    ).toBe(true);
    expect(
        screen.queryByText('client_messages.sheet.paused_notice'),
    ).toBeNull();
    fireEvent.click(screen.getByRole('checkbox', { name: /thomas@/ }));
    expect(
        screen.getByText('client_messages.sheet.paused_notice'),
    ).toBeTruthy();
    // The preview renders in the client's language before anything is sent.
    expect(
        await screen.findByText('Defekte Links auf shop.test: 7 gefunden', {
            exact: true,
        }),
    ).toBeTruthy();
    expect(screen.getByText('client_messages.sheet.language_de')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('client_messages.sheet.note'), {
        target: { value: 'Bitte prüfen.' },
    });
    // Choosing Plain re-renders the preview in that design; the send carries the same choice.
    expect(
        screen
            .getByRole('radio', {
                name: 'client_messages.sheet.design_branded',
            })
            .getAttribute('data-state'),
    ).toBe('checked');
    fireEvent.click(
        screen.getByRole('radio', {
            name: 'client_messages.sheet.design_plain',
        }),
    );
    await waitFor(() =>
        expect(
            requests.some(
                (request) =>
                    request.url.endsWith('/preview') &&
                    (request.body as { design?: string }).design === 'plain',
            ),
        ).toBe(true),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'client_messages.sheet.submit' }),
    );
    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false));
    const send = requests.filter(
        (request) => !request.url.endsWith('/preview'),
    );
    expect(send).toHaveLength(1);
    expect(send[0]?.url).toBe('/clients/9/client-messages');
    expect(send[0]?.body).toEqual({
        kind: 'findings',
        check_id: 4,
        channel_ids: [1, 2],
        one_off: null,
        note: 'Bitte prüfen.',
        attach: true,
        design: 'plain',
    });
    expect(send[0]?.headers?.['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    expect(toast.success).toHaveBeenCalledWith(
        'client_messages.sent({"client":"Müller GmbH"})',
        expect.anything(),
    );
});

it('starts in the design the page passes and previews it', async () => {
    render(
        <SendToClientSheet
            open
            onOpenChange={() => {}}
            client={{ id: 9, name: 'Müller GmbH' }}
            subject={{ kind: 'findings', check_id: 4 }}
            initialDesign="plain"
        />,
    );
    await screen.findByRole('checkbox', { name: /office@client\.test/ });
    expect(
        screen
            .getByRole('radio', { name: 'client_messages.sheet.design_plain' })
            .getAttribute('data-state'),
    ).toBe('checked');
    await waitFor(() =>
        expect(
            requests.some(
                (request) =>
                    request.url.endsWith('/preview') &&
                    (request.body as { design?: string }).design === 'plain',
            ),
        ).toBe(true),
    );
});

it('sends again only to a failed one-off address, with the note and design kept', async () => {
    render(
        <SendToClientSheet
            open
            onOpenChange={() => {}}
            client={{ id: 9, name: 'Müller GmbH' }}
            subject={{ kind: 'incident', incident_id: 3 }}
            resend={{
                note: 'FYI',
                attach: false,
                one_off: 'boss@client.test',
            }}
            initialDesign="plain"
        />,
    );
    const office = await screen.findByRole('checkbox', {
        name: /office@client\.test/,
    });
    expect(office.getAttribute('data-state')).toBe('unchecked');
    expect(
        (
            screen.getByLabelText(
                'client_messages.sheet.one_off',
            ) as HTMLInputElement
        ).value,
    ).toBe('boss@client.test');
    expect(
        screen
            .getByRole('radio', { name: 'client_messages.sheet.design_plain' })
            .getAttribute('data-state'),
    ).toBe('checked');
    // Incidents have no CSV.
    expect(screen.queryByText('client_messages.sheet.attach')).toBeNull();
});

it('refuses to send without a recipient', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => ({ channels: [] }),
        })),
    );
    render(
        <SendToClientSheet
            open
            onOpenChange={() => {}}
            client={{ id: 9, name: 'Müller GmbH' }}
            subject={{ kind: 'findings', check_id: 4 }}
            initialDesign="branded"
        />,
    );
    expect(
        await screen.findByText('client_messages.sheet.no_channels'),
    ).toBeTruthy();
    fireEvent.click(
        screen.getByRole('button', { name: 'client_messages.sheet.submit' }),
    );
    expect(
        await screen.findByText('client_messages.no_recipient'),
    ).toBeTruthy();
    expect(
        requests.filter((request) => !request.url.endsWith('/preview')),
    ).toHaveLength(0);
});

it('keeps the sheet open and says why when the server refuses the send, never "Sent"', async () => {
    // An archived channel cannot receive a message: the server answers 422 (design §4).
    sendResponse = json(422, {
        message: 'This channel cannot receive messages any more.',
        errors: {
            channel_ids: ['This channel cannot receive messages any more.'],
        },
    });
    const onOpenChange = vi.fn();
    render(
        <SendToClientSheet
            open
            onOpenChange={onOpenChange}
            client={{ id: 9, name: 'Müller GmbH' }}
            subject={{ kind: 'findings', check_id: 4 }}
            initialDesign="branded"
        />,
    );
    await screen.findByRole('checkbox', { name: /office@client\.test/ });
    fireEvent.click(
        screen.getByRole('button', { name: 'client_messages.sheet.submit' }),
    );
    expect(
        await screen.findByText(
            'This channel cannot receive messages any more.',
        ),
    ).toBeTruthy();
    expect(
        requests.filter((request) => !request.url.endsWith('/preview')),
    ).toHaveLength(1);
    expect(toast.success).not.toHaveBeenCalled();
    expect(onOpenChange).not.toHaveBeenCalledWith(false);
    expect(screen.queryByText('client_messages.send_failed')).toBeNull();
    // The refusal is about the chosen channels: it describes the To list, and the empty one-off field stays neutral.
    const to = screen.getByRole('group', { name: 'client_messages.sheet.to' });
    expect(
        document.getElementById(to.getAttribute('aria-describedby')!)
            ?.textContent,
    ).toBe('This channel cannot receive messages any more.');
    expect(
        screen
            .getByLabelText('client_messages.sheet.one_off')
            .getAttribute('aria-invalid'),
    ).toBe('false');
});
