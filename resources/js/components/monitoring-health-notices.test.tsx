import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { PageHeader } from './page-header';
import { MonitoringHealthNotices } from './monitoring-health-notices';

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
let causes: unknown;
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { overallHealth: { causes } } }),
}));
afterEach(cleanup);

const renderHeader = () =>
    render(
        <PageHeader
            title="Sites"
            description="18 sites"
            notices={<MonitoringHealthNotices />}
        />,
    );

it('renders each health cause as a warning notice below the page title with its action link', () => {
    causes = [
        {
            title_key: 'Email delivery is not configured',
            action_key: 'Configure email delivery',
            href: '/settings/system',
        },
    ];
    renderHeader();
    const notice = screen.getByRole('alert');
    expect(notice.textContent).toContain('Email delivery is not configured');
    expect(notice.getAttribute('data-tone')).toBe('warning');
    expect(
        screen
            .getByRole('link', { name: 'Configure email delivery' })
            .getAttribute('href'),
    ).toBe('/settings/system');
    const heading = screen.getByRole('heading', { level: 1 });
    expect(
        heading.compareDocumentPosition(notice) &
            Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
});

it('renders no notice when monitoring health has no causes', () => {
    causes = [];
    renderHeader();
    expect(screen.queryByRole('alert')).toBeNull();
});
