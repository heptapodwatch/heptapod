import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { Stepper } from '@/components/stepper';

afterEach(cleanup);

it('numbers the ordered steps, identifies only the current step and allows returning to a completed step', () => {
    const change = vi.fn();
    const view = render(
        <Stepper
            label="Add site"
            steps={['Address', 'Client', 'Checks']}
            current={2}
            onStepChange={change}
        />,
    );
    const steps = within(
        screen.getByRole('list', { name: 'Add site' }),
    ).getAllByRole('listitem');
    expect(steps.map((item) => item.getAttribute('aria-current'))).toEqual([
        null,
        null,
        'step',
    ]);
    fireEvent.click(screen.getByRole('button', { name: '1. Address' }));
    expect(change).toHaveBeenCalledWith(0);
    view.rerender(
        <Stepper
            label="Add site"
            steps={['Address', 'Client', 'Checks']}
            current={0}
            onStepChange={change}
            disabled
        />,
    );
    expect(
        (screen.getByRole('button', { name: '2. Client' }) as HTMLButtonElement)
            .disabled,
    ).toBe(true);
    view.rerender(
        <Stepper
            label="Add site"
            steps={['Address', 'Client', 'Checks']}
            current={1}
        />,
    );
    expect(screen.queryByRole('button')).toBeNull();
    expect(
        screen.getByText('Client').closest('li')?.getAttribute('aria-current'),
    ).toBe('step');
});
