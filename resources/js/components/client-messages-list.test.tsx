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
    usePage: () => ({ props: { timezone: 'UTC', locale: 'en' } }),
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

it('opens the message as sent and offers its stored CSV from the row actions', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { assign });
    render(<ClientMessagesList client={{ id: 3, name: 'Müller GmbH' }} />);
    fireEvent.pointerDown(
        // The row menu names the row once: "More actions for <subject>".
        await screen.findByRole('button', {
            name: 'More actions for :name({"name":"Broken links on shop.test: 2 found"})',
        }),
        { button: 0, ctrlKey: false },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'Download CSV' }),
    );
    expect(assign).toHaveBeenCalledWith(
        '/clients/3/client-messages/7/attachment',
    );

    fireEvent.pointerDown(
        screen.getByRole('button', {
            name: 'More actions for :name({"name":"Broken links on shop.test: 2 found"})',
        }),
        { button: 0, ctrlKey: false },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', {
            name: 'client_messages.list.open',
        }),
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
