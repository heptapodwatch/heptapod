import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { useState } from 'react';
import InputError, { fieldErrorProps } from './input-error';
import PasswordInput from './password-input';
import { Disclosure } from './ui/disclosure';
import { NumberField, type NumberFieldValue } from './ui/number-field';
import {
    WeekdayToggleGroup,
    type WeekdayNumbering,
} from './ui/weekday-toggle-group';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));
afterEach(cleanup);

function Weekdays({
    numbering,
    initial,
}: {
    numbering: WeekdayNumbering;
    initial: number[];
}) {
    const [days, setDays] = useState(initial);
    return (
        <>
            <span id="days-label">Days</span>
            <WeekdayToggleGroup
                id="days"
                numbering={numbering}
                labelledBy="days-label"
                value={days}
                onValueChange={setDays}
            />
            <output data-testid="days">{JSON.stringify(days)}</output>
        </>
    );
}

it.each([
    ['iso', [1, 2, 3, 4, 5, 6, 7]],
    ['sunday-zero', [1, 2, 3, 4, 5, 6, 0]],
] as const)(
    'shows Monday first and submits %s numbering sorted ascending',
    (numbering, displayed) => {
        render(<Weekdays numbering={numbering} initial={[3]} />);
        const buttons = screen.getAllByRole('button');
        expect(buttons.map((button) => button.textContent)).toEqual([
            'Mon',
            'Tue',
            'Wed',
            'Thu',
            'Fri',
            'Sat',
            'Sun',
        ]);
        expect(buttons.map((button) => button.id)).toEqual(
            displayed.map((day) => `days-${day}`),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Sunday' }));
        fireEvent.click(screen.getByRole('button', { name: 'Monday' }));
        expect(screen.getByTestId('days').textContent).toBe(
            numbering === 'iso' ? '[1,3,7]' : '[0,1,3]',
        );
        expect(
            screen
                .getByRole('button', { name: 'Wednesday' })
                .getAttribute('aria-pressed'),
        ).toBe('true');
        fireEvent.click(screen.getByRole('button', { name: 'Wednesday' }));
        expect(screen.getByTestId('days').textContent).toBe(
            numbering === 'iso' ? '[1,7]' : '[0,1]',
        );
    },
);

function Number({ initial }: { initial: NumberFieldValue }) {
    const [value, setValue] = useState(initial);
    return (
        <>
            <label htmlFor="n">Interval</label>
            <NumberField
                id="n"
                value={value}
                onValueChange={setValue}
                min={1}
                max={3}
                unit="min"
            />
            <output data-testid="n">{JSON.stringify(value)}</output>
        </>
    );
}

it('steps with arrow keys and buttons within min/max and keeps typed values for server validation', () => {
    render(<Number initial={2} />);
    const input = screen.getByRole('spinbutton', { name: 'Interval' });
    expect(input.getAttribute('type')).toBe('text');
    expect(screen.getByText('min')).not.toBeNull();
    fireEvent.keyDown(input, { key: 'ArrowUp' });
    expect(screen.getByTestId('n').textContent).toBe('3');
    fireEvent.keyDown(input, { key: 'ArrowUp' });
    expect(screen.getByTestId('n').textContent).toBe('3');
    expect(
        (screen.getByRole('button', { name: 'Increase' }) as HTMLButtonElement)
            .disabled,
    ).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Decrease' }));
    fireEvent.click(screen.getByRole('button', { name: 'Decrease' }));
    expect(screen.getByTestId('n').textContent).toBe('1');
    fireEvent.keyDown(input, { key: 'ArrowDown' });
    expect(screen.getByTestId('n').textContent).toBe('1');
    fireEvent.change(input, { target: { value: '12' } });
    expect(screen.getByTestId('n').textContent).toBe('12');
    expect(input.getAttribute('aria-invalid')).toBeNull();
    fireEvent.change(input, { target: { value: '' } });
    expect(screen.getByTestId('n').textContent).toBe('null');
});

it.each(['12x', '1e3', '١٢'])(
    'keeps typed or pasted %s verbatim, marks it invalid and passes it through',
    (raw) => {
        render(<Number initial={2} />);
        const input = screen.getByRole('spinbutton', { name: 'Interval' });
        fireEvent.paste(input);
        fireEvent.change(input, { target: { value: raw } });
        expect((input as HTMLInputElement).value).toBe(raw);
        expect(screen.getByTestId('n').textContent).toBe(JSON.stringify(raw));
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-valuenow')).toBeNull();
    },
);

it('starts stepping at min from an empty field', () => {
    render(<Number initial={null} />);
    const input = screen.getByRole('spinbutton', { name: 'Interval' });
    fireEvent.keyDown(input, { key: 'ArrowUp' });
    expect(screen.getByTestId('n').textContent).toBe('1');
});

it('discloses its content through the trigger with aria-expanded', () => {
    render(
        <Disclosure summary="Retention configuration">
            <p>Keep 7 daily backups</p>
        </Disclosure>,
    );
    const trigger = screen.getByRole('button', {
        name: 'Retention configuration',
    });
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByText('Keep 7 daily backups')).toBeNull();
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByText('Keep 7 daily backups')).not.toBeNull();
});

it('ties a field to its InputError only while there is an error, after its help text', () => {
    function Field({ error }: { error?: string }) {
        return (
            <>
                <label htmlFor="current_password">Current password</label>
                <PasswordInput
                    id="current_password"
                    {...fieldErrorProps('current_password', error, 'pw-help')}
                />
                <p id="pw-help">Use a long password.</p>
                <InputError field="current_password" message={error} />
            </>
        );
    }
    const { rerender } = render(<Field />);
    const field = screen.getByLabelText('Current password');
    expect(field.getAttribute('aria-invalid')).toBeNull();
    expect(field.getAttribute('aria-describedby')).toBe('pw-help');

    rerender(<Field error="The password is incorrect." />);
    expect(field.getAttribute('aria-invalid')).toBe('true');
    expect(field.getAttribute('aria-describedby')).toBe(
        'pw-help current_password-error',
    );
    expect(document.getElementById('current_password-error')?.textContent).toBe(
        'The password is incorrect.',
    );
});
