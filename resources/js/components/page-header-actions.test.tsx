import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    PageHeaderAction,
    PageHeaderActions,
} from '@/components/page-header-actions';

vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
afterEach(cleanup);

function header(overflowSecondary: true | 'always') {
    render(
        <PageHeaderActions
            primary={<button type="button">Run all checks…</button>}
            secondary={<PageHeaderAction>Pause site</PageHeaderAction>}
            overflowSecondary={overflowSecondary}
            secondaryActionCount={1}
        />,
    );
}

it('puts even a single secondary action into More actions for site headers', () => {
    header('always');
    expect(screen.getByRole('button', { name: 'More actions' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Pause site' })).toBeNull();
    expect(
        screen.getByRole('button', { name: 'Run all checks…' }),
    ).toBeTruthy();
});

it('keeps a single secondary action as a button for other overflow headers', () => {
    header(true);
    expect(screen.queryByRole('button', { name: 'More actions' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Pause site' })).toBeTruthy();
});
