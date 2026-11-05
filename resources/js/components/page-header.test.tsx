import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { PageHeader } from '@/components/page-header';

vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
afterEach(cleanup);

it('renders the one Refresh action before the page actions and calls it', () => {
    const onRefresh = vi.fn();
    render(
        <PageHeader
            title="Members"
            description="People with access."
            refresh={{ onRefresh, refreshing: false }}
            actions={<button type="button">Invite</button>}
        />,
    );
    const buttons = screen.getAllByRole('button');
    expect(buttons.map((button) => button.textContent)).toEqual([
        'Refresh',
        'Invite',
    ]);
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    expect(onRefresh).toHaveBeenCalledOnce();
});

it('shows Refreshing… and blocks a second refresh while one runs', () => {
    render(
        <PageHeader
            title="System health"
            description="Instance state."
            refresh={{ onRefresh: () => {}, refreshing: true }}
        />,
    );
    const button = screen.getByRole('button', { name: 'Refreshing…' });
    expect(button.hasAttribute('disabled')).toBe(true);
});
