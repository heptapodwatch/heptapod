import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { receiveLiveSignal } from '@/lib/live-store';
import type { RequestedRun } from '@/hooks/use-requested-run';
import { DnsComparison } from './dns-comparison';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, values: Record<string, string> = {}) =>
            key.replace(
                /{{(\w+)}}/g,
                (_, name: string) => values[name] ?? name,
            ),
        i18n: { language: 'en' },
    }),
}));
vi.mock('@inertiajs/react', () => ({
    router: { post, reload: vi.fn() },
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));

const page = {
    can_run: true,
    check: { id: 92682, result: 'failed', pending_recheck: false },
    site: { id: 42 },
    schedule: {
        run: {
            check_id: 92682,
            phase: 'done',
            attempt_id: null,
            queue: null,
            queue_position: null,
            progress_n: null,
            progress_total: null,
            phase_label: null,
            started_at: null,
            actor: null,
            due_at: null,
            can_cancel: false,
            requested_at: null,
        },
    },
} satisfies Parameters<typeof DnsComparison>[0]['page'];

function comparison(
    overrides: Partial<Heptapod.Addons.Dns.DnsComparisonData> = {},
): Heptapod.Addons.Dns.DnsComparisonData {
    return {
        check_type: 'dns',
        accepted_run_id: 1,
        current_run_id: 2,
        accepted_at: null,
        accepted_by: null,
        accept_would_change_baseline: true,
        current_issues: [],
        accepted_records: {},
        current_records: {},
        changes: {},
        records_truncated: false,
        timeline: [],
        ...overrides,
    };
}

function requestedRun(): RequestedRun {
    return {
        state: { phase: 'idle' },
        finished: null,
        request: vi.fn(),
        accept: vi.fn(),
        abandon: vi.fn(),
        consume: vi.fn(),
    };
}

function signal(phase: 'queued' | 'started' | 'finished') {
    act(() =>
        receiveLiveSignal({
            topic: `run.${phase}`,
            ids: { check: page.check.id },
            at: '2026-10-22T12:00:00Z',
            run: {
                check_id: page.check.id,
                phase,
                queue_position: null,
                progress_n: null,
                progress_total: null,
                started_at: null,
                actor: null,
            },
        }),
    );
}

afterEach(() => {
    cleanup();
    signal('finished');
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

it('replaces accept with an explanation during queued and running phases and returns it live on finish', () => {
    render(
        <DnsComparison
            page={page}
            history={comparison()}
            requestedRun={requestedRun()}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Accept as normal' }),
    ).not.toBeNull();
    for (const phase of ['queued', 'started'] as const) {
        signal(phase);
        expect(
            screen.queryByRole('button', { name: 'Accept as normal' }),
        ).toBeNull();
        expect(screen.getByRole('status').textContent).toBe(
            'A check is running. You can accept the records when it has finished.',
        );
    }
    signal('finished');
    expect(screen.queryByRole('status')).toBeNull();
    expect(
        screen.queryByRole('button', { name: 'Accept as normal' }),
    ).not.toBeNull();
});

it('shows checking only while an accepted recheck is active, then waiting when no run is active', () => {
    render(
        <DnsComparison
            page={{ ...page, check: { ...page.check, pending_recheck: true } }}
            history={comparison()}
            requestedRun={requestedRun()}
        />,
    );
    signal('queued');
    expect(screen.getByRole('status').textContent).toBe(
        'Accepted · checking now…',
    );
    signal('started');
    expect(screen.getByRole('status').textContent).toBe(
        'Accepted · checking now…',
    );
    signal('finished');
    expect(screen.getByRole('status').textContent).toBe(
        'Accepted; waiting for the new DNS check',
    );
});

it('names the accepted actor after a successful confirming run', () => {
    render(
        <DnsComparison
            page={{ ...page, check: { ...page.check, result: 'succeeded' } }}
            history={comparison({
                accepted_by: 'Demo Owner',
                accept_would_change_baseline: false,
            })}
            requestedRun={requestedRun()}
        />,
    );
    expect(screen.getByText('Accepted as normal by Demo Owner')).not.toBeNull();
    expect(
        screen.queryByRole('button', { name: 'Accept as normal' }),
    ).toBeNull();
});

it('names unfixable issues in the accept dialog and tracks the own request', () => {
    const own = requestedRun();
    render(
        <DnsComparison
            page={page}
            history={comparison({
                current_issues: [
                    {
                        type: 'dns.issue.nameservers_out_of_sync',
                        host: 'example.com',
                        record_type: null,
                    },
                ],
            })}
            requestedRun={own}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Accept as normal' }));
    const dialog = screen.getByRole('dialog');
    expect(dialog.textContent).toContain(
        'Accepting stores the current records. It does not fix: dns.issue.nameservers_out_of_sync',
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Accept as normal' }),
    );
    expect(own.request).toHaveBeenCalledOnce();
    expect(post).toHaveBeenCalledWith(
        '/sites/42/checks/92682/accept-dns',
        { accepted_run_id: 2 },
        expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
});

it('hides accept and gives actionable guidance when only unfixable issues remain', () => {
    render(
        <DnsComparison
            page={page}
            history={comparison({
                accept_would_change_baseline: false,
                current_issues: [
                    {
                        type: 'dns.issue.nameservers_out_of_sync',
                        host: 'example.com',
                        record_type: null,
                    },
                ],
            })}
            requestedRun={requestedRun()}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Accept as normal' }),
    ).toBeNull();
    expect(
        screen.getByText(
            'dns.issue.nameservers_out_of_sync dns.guidance.nameservers_out_of_sync',
        ),
    ).not.toBeNull();
});

it('does not claim records differ when only nameservers disagree after accept', () => {
    render(
        <DnsComparison
            page={page}
            history={comparison({
                accepted_by: 'Demo Owner',
                accept_would_change_baseline: false,
                current_issues: [
                    {
                        type: 'dns.issue.nameservers_out_of_sync',
                        host: 'example.com',
                        record_type: null,
                    },
                ],
            })}
            requestedRun={requestedRun()}
        />,
    );
    expect(screen.getByRole('status').textContent).toBe(
        'dns.issue.nameservers_out_of_sync dns.guidance.nameservers_out_of_sync',
    );
    expect(screen.queryByText('Accepted as normal by Demo Owner')).toBeNull();
});

it('announces one status with running taking priority over differences and truncation', () => {
    render(
        <DnsComparison
            page={page}
            history={comparison({
                accepted_by: 'Demo Owner',
                records_truncated: true,
            })}
            requestedRun={requestedRun()}
        />,
    );
    signal('started');
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByRole('status').textContent).toBe(
        'A check is running. You can accept the records when it has finished.',
    );
});
