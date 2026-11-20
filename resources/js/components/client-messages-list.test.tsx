import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ClientMessagesList } from '@/components/client-messages-list';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { on: () => () => {}, visit: vi.fn() },
    useHttp: () => ({}),
    usePage: () => ({
        props: { timezone: 'UTC', locale: 'en', exportRowLimit: 10000 },
    }),
}));
vi.mock('react-i18next', () => {
    const t = (key: string, values?: Record<string, unknown>) =>
        values ? `${key}(${JSON.stringify(values)})` : key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});

const row: Heptapod.Data.Ui.ClientMessageRowData = {
    id: 11,
    event_id: 7,
    at: '2026-10-28T21:40:00Z',
    kind: 'findings',
    subject: 'Broken links on shop.test: 2 found',
    origin: 'manual',
    actor: 'Anna',
    channel: 'Office',
    address: 'office@client.test',
    state: 'accepted',
    reason: null,
    attachment: true,
    resend: null,
};

beforeEach(() => {
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
        vi.fn(async (url: string) => ({
            ok: true,
            json: async () =>
                url.includes('/client-messages/7')
                    ? {
                          subject: 'Broken links on shop.test: 2 found',
                          html: '<p>Hello,</p>',
                      }
                    : { rows: [row], next_before: null, can_send: true },
        })),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('opens the message as sent and exports its stored attachment from the row', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { assign });
    render(<ClientMessagesList client={{ id: 3, name: 'Müller GmbH' }} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Export' }));
    fireEvent.click(screen.getByRole('button', { name: 'export.download' }));
    expect(assign).toHaveBeenCalledWith(
        '/clients/3/client-messages/7/attachment.csv',
    );

    // Copy fetches the server's Markdown of the same attachment, not text built in the browser.
    let copiedText: string | undefined;
    const write = vi.fn(async (items: ClipboardItem[]) => {
        copiedText = await (await items[0]?.getType('text/plain'))?.text();
    });
    vi.stubGlobal('navigator', { clipboard: { write } });
    vi.stubGlobal(
        'ClipboardItem',
        class {
            constructor(private readonly data: Record<string, Promise<Blob>>) {}
            getType(type: string) {
                return this.data[type];
            }
        },
    );
    const messages = globalThis.fetch;
    const fetchExport = vi.fn(async (url: string, init?: RequestInit) =>
        url.includes('/attachment.')
            ? ({ ok: true, text: async () => '| URL |' } as Response)
            : messages(url, init),
    );
    vi.stubGlobal('fetch', fetchExport);
    fireEvent.click(screen.getByRole('button', { name: 'Export' }));
    fireEvent.click(screen.getByRole('radio', { name: 'export.format.md' }));
    fireEvent.click(screen.getByRole('button', { name: 'Copy' }));
    expect(
        await screen.findByRole('button', { name: 'export.copied' }),
    ).toBeTruthy();
    expect(fetchExport).toHaveBeenCalledWith(
        '/clients/3/client-messages/7/attachment.md',
        expect.anything(),
    );
    expect(write).toHaveBeenCalledOnce();
    expect(copiedText).toBe('| URL |');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    fireEvent.click(
        screen.getByRole('button', { name: 'client_messages.list.open' }),
    );
    await waitFor(() =>
        expect(
            screen.getByTitle('client_messages.list.sent_title'),
        ).toBeTruthy(),
    );
    expect(
        screen.getAllByText('Broken links on shop.test: 2 found'),
    ).toHaveLength(2);
});

it('shows each delivery outcome with the shared status tone', async () => {
    const outcomes = {
        accepted: ['ok', 'Accepted'],
        rejected: ['failed', 'client_messages.list.not_delivered'],
        failed: ['failed', 'client_messages.list.not_delivered'],
        logged: ['warning', 'Logged, not delivered'],
        suppressed: ['paused', 'Not sent'],
        pending: ['pending', 'client_messages.list.pending'],
        sending: ['pending', 'client_messages.list.pending'],
    } satisfies Record<Heptapod.Enums.DeliveryOutcome, [string, string]>;
    const rows = Object.keys(outcomes).map((state, index) => ({
        ...row,
        id: 20 + index,
        kind: 'alert' as const,
        state: state as Heptapod.Enums.DeliveryOutcome,
    }));
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => ({ rows, next_before: null, can_send: true }),
        })),
    );
    render(<ClientMessagesList client={{ id: 3, name: 'Müller GmbH' }} />);
    await screen.findAllByText('Broken links on shop.test: 2 found');
    expect(
        [...document.querySelectorAll('.status-badge')].map((badge) => [
            badge.getAttribute('data-state'),
            badge.getAttribute('aria-label'),
        ]),
    ).toEqual(Object.values(outcomes));
});

it('says why an automatic alert was not sent', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => ({
                rows: [
                    {
                        ...row,
                        kind: 'alert',
                        subject: 'Outage · API',
                        state: 'suppressed',
                        reason: 'Monitoring stopped',
                    },
                ],
                next_before: null,
                can_send: true,
            }),
        })),
    );
    render(<ClientMessagesList client={{ id: 3, name: 'Müller GmbH' }} />);
    expect(await screen.findByText('Outage · API')).toBeTruthy();
    expect(screen.getByText('Monitoring stopped')).toBeTruthy();
});
