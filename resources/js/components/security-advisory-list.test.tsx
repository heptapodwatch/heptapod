import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { SecurityAdvisoryList } from './security-advisory-list';

afterEach(cleanup);

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

function advisory(
    pkg: string,
    id: string,
    severity: string | null,
    reported = '2026-01-01T00:00:00+00:00',
): Heptapod.Addons.ApplicationHealth.SecurityAdvisoryData {
    return {
        package: pkg,
        advisory_id: id,
        title: `Advisory ${id}`,
        link: `https://example.com/${id}`,
        cve: id,
        severity,
        affected_versions: '<1.0.0',
        reported_at: reported,
    };
}

it('opens the most severe package first and toggles each package with a disclosure button', () => {
    render(
        <SecurityAdvisoryList
            more={false}
            advisories={[
                // Server order is by package name; the list ranks packages by their most severe advisory.
                advisory('aaa/medium', 'CVE-1', 'medium'),
                advisory('aaa/medium', 'CVE-2', 'low'),
                advisory('zzz/critical', 'CVE-3', 'low'),
                advisory('zzz/critical', 'CVE-4', 'critical'),
                // An untrusted severity renders as text instead of breaking the list.
                advisory('mmm/unknown', 'CVE-5', 'toString'),
            ]}
        />,
    );
    const table = screen.getAllByRole('table')[0]!;
    const summary = (name: string) =>
        [...table.querySelectorAll('button[aria-expanded]')].find((button) =>
            button.textContent?.startsWith(name),
        )!;
    const rowsOf = (name: string) =>
        document.getElementById(summary(name).getAttribute('aria-controls')!)!;

    expect(summary('zzz/critical').getAttribute('aria-expanded')).toBe('true');
    expect(summary('aaa/medium').getAttribute('aria-expanded')).toBe('false');
    expect(rowsOf('aaa/medium').hidden).toBe(true);
    // Within the open package: severity first.
    expect(
        [...rowsOf('zzz/critical').querySelectorAll('tr')].map(
            (row) => row.querySelector('td:nth-child(2) p')!.textContent,
        ),
    ).toEqual(['CVE-4', 'CVE-3']);

    fireEvent.click(summary('aaa/medium'));
    expect(summary('aaa/medium').getAttribute('aria-expanded')).toBe('true');
    expect(rowsOf('aaa/medium').hidden).toBe(false);
    fireEvent.click(summary('mmm/unknown'));
    expect(rowsOf('mmm/unknown').textContent).toContain('toString');
});
