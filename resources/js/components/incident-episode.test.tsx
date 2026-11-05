import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { ComponentProps } from 'react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { IncidentEpisode } from '@/components/incident-episode';
import { incidentPageFixture, incidentSnapshot } from '@/fixtures/design';
import type { RequestedRun } from '@/hooks/use-requested-run';

vi.mock('react-i18next', () => {
    const t = (key: string, values: Record<string, string> = {}) =>
        key.replace(/{{(\w+)}}/g, (_, name: string) => values[name] ?? name);
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@inertiajs/react', () => ({
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    usePage: () => ({
        url: '/incidents/1',
        props: {
            timezone: 'UTC',
            errors: {},
            auth: { canManageChannels: true },
        },
    }),
    useForm: (data: Record<string, unknown>) => ({
        data,
        errors: {},
        processing: false,
        setData: vi.fn(),
        put: vi.fn(),
    }),
    useHttp: () => ({
        processing: false,
        get: () => new Promise(() => {}),
        cancel: vi.fn(),
    }),
    router: { on: () => () => {}, post: vi.fn(), delete: vi.fn() },
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

afterEach(cleanup);

const requestedRun: RequestedRun = {
    state: { phase: 'idle' },
    finished: null,
    request: vi.fn(),
    accept: vi.fn(),
    abandon: vi.fn(),
    consume: vi.fn(),
};

const delivery: Heptapod.Data.Ui.IncidentDeliveryData = {
    address: 'ops@example.test',
    event_type: 'cron.task_result_changed',
    state: 'logged',
    diagnosis: null,
    sent_at: null,
    scheduled_at: '2026-10-21T11:50:00Z',
    event_id: 900,
    id: 901,
    digest: false,
    can_preview: false,
    event_label: 'Nightly backup: Failed',
    timeline_time: '2026-10-21T11:50:00Z',
    sent_delay_seconds: null,
    retry_count: 0,
};

function openPage(): Heptapod.Data.Ui.IncidentDetailPageData {
    const incident = incidentSnapshot.find(
        (row) => row.check === 'uptime' && row.resolved_at === null,
    );
    if (!incident) throw new Error('Open uptime incident fixture missing');
    return {
        ...incidentPageFixture(incident.id, 'populated'),
        can_run: true,
    };
}

function resolvedPage(): Heptapod.Data.Ui.IncidentDetailPageData {
    const page = openPage();
    return {
        ...page,
        incident: {
            ...page.incident,
            started_at: '2026-10-21T11:00:00Z',
            resolved_at: '2026-10-21T11:26:00Z',
            duration_seconds: 26 * 60,
        },
        evidence: {
            facts: {},
            summary: 'HTTP 503 at opening',
            summary_params: {},
            opened_at: '2026-10-21T11:00:00Z',
            recovered_at: '2026-10-21T11:26:00Z',
            recovery_summary: 'Site is reachable',
            recovery_summary_params: {},
            retained_good_results_since_recovery: 3,
            good_results_capped: false,
        },
        other_deliveries: [delivery],
    };
}

function renderEpisode(
    page: Heptapod.Data.Ui.IncidentDetailPageData,
    headline: string,
) {
    render(
        <TooltipProvider>
            <IncidentEpisode
                page={page}
                headline={headline}
                requestedRun={requestedRun}
                ownRun={null}
                onPreview={vi.fn()}
            />
        </TooltipProvider>,
    );
}

it('renders an open incident with the live state, Run check and current evidence', () => {
    renderEpisode(openPage(), 'Shop is down.');

    const header = screen.getByRole('banner');
    expect(
        within(header).getByRole('heading', { level: 1 }).textContent?.trim(),
    ).toBe('Shop is down.');
    expect(header.textContent).toContain('incidents.state.open');
    // One meta line: " · " only between facts (state + duration · since · #id).
    expect(header.textContent).toMatch(
        /incidents\.state\.open \S+\s·\ssince \S.*\s·\s#\d/,
    );
    expect(screen.getByRole('button', { name: 'Run check' })).toBeTruthy();
    expect(screen.queryByText('Evidence at close')).toBeNull();
    // The live Now line closes the open timeline.
    expect(document.body.textContent).toContain('Now · incidents.state.open');
});

it('renders a resolved incident on the same page with its close, evidence at close and no live controls', () => {
    renderEpisode(resolvedPage(), 'Shop is back up.');

    const header = screen.getByRole('banner');
    expect(
        within(header).getByRole('heading', { level: 1 }).textContent?.trim(),
    ).toBe('Shop is back up.');
    expect(header.textContent).toContain('Resolved after 26m');
    // Same meta builder as the open state: separators only between facts.
    expect(header.textContent).toMatch(
        /ResolvedResolved after 26m\s·\s\S.* → \S.*\s·\s#\d/,
    );
    expect(header.textContent).not.toContain('incidents.state.open');
    // Episode-scoped evidence only; no current-state panel and no run action.
    expect(screen.getByText('Evidence at close')).toBeTruthy();
    expect(screen.getByText('HTTP 503 at opening')).toBeTruthy();
    expect(screen.getByText('Site is reachable')).toBeTruthy();
    expect(screen.queryByText('Latest attempt')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Run check' })).toBeNull();
    expect(document.body.textContent).not.toContain(
        'Now · incidents.state.open',
    );
    // The same note form in both states.
    expect(screen.getByRole('textbox', { name: 'Internal note' })).toBeTruthy();
    // Mail of events outside the timeline, named by its event.
    const other = screen
        .getByText('Other notifications')
        .closest('[data-slot="card"]') as HTMLElement;
    expect(other.textContent).toContain('Nightly backup: Failed · logged');
    expect(other.textContent).toContain('logged');
});
