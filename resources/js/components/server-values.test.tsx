import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { ServerGaugeCells } from '@/components/server-values';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));

afterEach(cleanup);

const thresholds = {
    disk_percent: 90,
    disk_minutes: 15,
    mounts: [],
    memory_percent: 90,
    memory_minutes: 10,
    load_per_core: 2,
    load_minutes: 15,
    no_report_minutes: 2,
};

function server(load5: number): Heptapod.Addons.Servers.Data.ServerRowData {
    return {
        id: 1,
        name: 'web-1',
        state: 'reporting',
        last_report_at: '2026-10-21T12:00:00Z',
        agent_version: '0.1.0',
        values: values(load5),
        thresholds,
        ignored_mounts: [],
        site_count: 0,
        hostname: 'web-1',
        agent_os: 'linux',
        agent_arch: 'amd64',
        agent_distro: 'Debian 13',
        agent_kernel: '6.12',
        ips: [],
    };
}

/** One sample of a 4-vCPU server: its load alert sits at 8.0, its near band starts at 6.0 (75 %). */
function values(load5: number): Heptapod.Addons.Servers.Data.ServerValuesData {
    return {
        observed_at: '2026-10-21T12:00:00Z',
        cores: 4,
        cpu_used_percent: 23,
        load_5: load5,
        memory_total_bytes: 100,
        memory_available_bytes: 50,
        filesystems: [{ mount: '/', total_bytes: 100, free_bytes: 50 }],
        uptime_seconds: 3600,
        reboot_required: null,
        updates_pending: null,
        updates_security: null,
    };
}

function loadCell(load5: number) {
    render(
        <table>
            <tbody>
                <tr>
                    <ServerGaugeCells server={server(load5)} />
                </tr>
            </tbody>
        </table>,
    );
    const cell = screen.getAllByRole('cell')[2]!;
    return {
        text: cell.textContent,
        coloured: cell.querySelector('.text-status-warn, .text-status-down')
            ?.className,
    };
}

it('shows the 5-minute load against the cores, uncoloured below 75 % of its threshold', () => {
    expect(loadCell(5.9)).toEqual({
        text: '5.9 / 4 vCPU',
        coloured: undefined,
    });
});

it('turns the load orange from 75 % of its threshold, with the near-threshold word', () => {
    const cell = loadCell(6);
    expect(cell.text).toBe('6.0 / 4 vCPUNear threshold');
    expect(cell.coloured).toContain('text-status-warn');
});

it('turns the load red with "High load" at its threshold of 2x vCPU', () => {
    const cell = loadCell(8);
    expect(cell.text).toBe('8.0 / 4 vCPUHigh load');
    expect(cell.coloured).toContain('text-status-down');
});
