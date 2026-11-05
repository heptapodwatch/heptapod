import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it } from 'vitest';
import { useState } from 'react';
import { CheckboxField } from './ui/checkbox';
import { RadioCards } from './ui/radio-cards';
import { RadioGroup, RadioGroupItem } from './ui/radio-group';

afterEach(cleanup);

function Checkbox({ disabled = false }: { disabled?: boolean }) {
    const [checked, setChecked] = useState(false);
    return (
        <>
            <CheckboxField
                checked={checked}
                disabled={disabled}
                onCheckedChange={(next) => setChecked(next === true)}
                description="Sent once per day."
            >
                Email me system alerts
            </CheckboxField>
            <output data-testid="checked">{String(checked)}</output>
        </>
    );
}

it('toggles a CheckboxField by label click and Space, names it by its label and describes it', async () => {
    const user = userEvent.setup();
    render(<Checkbox />);
    const box = screen.getByRole('checkbox', {
        name: 'Email me system alerts',
    });
    expect(box.getAttribute('aria-checked')).toBe('false');
    expect(
        document.getElementById(box.getAttribute('aria-describedby')!)
            ?.textContent,
    ).toBe('Sent once per day.');
    await user.click(screen.getByText('Email me system alerts'));
    expect(box.getAttribute('aria-checked')).toBe('true');
    expect(screen.getByTestId('checked').textContent).toBe('true');
    expect(document.activeElement).toBe(box);
    await user.keyboard(' ');
    expect(box.getAttribute('aria-checked')).toBe('false');
});

it('ignores label clicks and keys on a disabled CheckboxField', async () => {
    const user = userEvent.setup();
    render(<Checkbox disabled />);
    const box = screen.getByRole('checkbox', {
        name: 'Email me system alerts',
    });
    expect((box as HTMLButtonElement).disabled).toBe(true);
    await user.click(screen.getByText('Email me system alerts'));
    fireEvent.keyDown(box, { key: ' ' });
    expect(box.getAttribute('aria-checked')).toBe('false');
    expect(screen.getByTestId('checked').textContent).toBe('false');
});

function Cards({ disabled = false }: { disabled?: boolean }) {
    const [role, setRole] = useState<'Admin' | 'Member'>('Member');
    return (
        <>
            <span id="role-label">Role</span>
            <RadioCards
                labelledBy="role-label"
                value={role}
                onChange={setRole}
                disabled={disabled}
                options={[
                    {
                        value: 'Admin',
                        label: 'Admin',
                        description: 'Manage clients.',
                    },
                    {
                        value: 'Member',
                        label: 'Member',
                        description: 'View selected clients.',
                    },
                ]}
            />
            <output data-testid="role">{role}</output>
        </>
    );
}

it('selects RadioCards by card click and arrow keys, with label as name and consequence as description', async () => {
    const user = userEvent.setup();
    render(<Cards />);
    expect(screen.getByRole('radiogroup', { name: 'Role' })).not.toBeNull();
    const admin = screen.getByRole('radio', { name: 'Admin' });
    const member = screen.getByRole('radio', { name: 'Member' });
    expect(member.getAttribute('aria-checked')).toBe('true');
    expect(
        document.getElementById(admin.getAttribute('aria-describedby')!)
            ?.textContent,
    ).toBe('Manage clients.');
    await user.click(screen.getByText('Manage clients.'));
    expect(admin.getAttribute('aria-checked')).toBe('true');
    expect(screen.getByTestId('role').textContent).toBe('Admin');
    admin.focus();
    await user.keyboard('{ArrowDown>}');
    // Radix checks the radio that roving focus reaches while the arrow key is held; focus moves in a timeout.
    await waitFor(() =>
        expect(member.getAttribute('aria-checked')).toBe('true'),
    );
    expect(document.activeElement).toBe(member);
    await user.keyboard('{/ArrowDown}');
});

it('ignores clicks on disabled RadioCards', async () => {
    const user = userEvent.setup();
    render(<Cards disabled />);
    const admin = screen.getByRole('radio', { name: 'Admin' });
    expect((admin as HTMLButtonElement).disabled).toBe(true);
    await user.click(screen.getByText('Manage clients.'));
    expect(screen.getByTestId('role').textContent).toBe('Member');
});

function Until() {
    const [until, setUntil] = useState('manual');
    return (
        <>
            <span id="until-label">Until</span>
            <RadioGroup
                aria-labelledby="until-label"
                orientation="vertical"
                value={until}
                onValueChange={setUntil}
            >
                <RadioGroupItem value="manual">I resume it</RadioGroupItem>
                <RadioGroupItem value="hour">1 hour</RadioGroupItem>
                <RadioGroupItem value="tomorrow" disabled>
                    Tomorrow
                </RadioGroupItem>
            </RadioGroup>
            <output data-testid="until">{until}</output>
        </>
    );
}

it('selects RadioGroup items by label click and arrows, skipping disabled items, and focuses the checked radio from the group label', async () => {
    const user = userEvent.setup();
    render(<Until />);
    const hour = screen.getByRole('radio', { name: '1 hour' });
    const manual = screen.getByRole('radio', { name: 'I resume it' });
    await user.click(screen.getByText('1 hour'));
    expect(hour.getAttribute('aria-checked')).toBe('true');
    expect(screen.getByTestId('until').textContent).toBe('hour');
    hour.focus();
    await user.keyboard('{ArrowDown>}');
    // Tomorrow is disabled, so roving focus wraps to the first item.
    await waitFor(() =>
        expect(manual.getAttribute('aria-checked')).toBe('true'),
    );
    await user.click(screen.getByText('Tomorrow'));
    expect(screen.getByTestId('until').textContent).toBe('manual');
    (document.activeElement as HTMLElement).blur();
    await user.click(screen.getByText('Until'));
    expect(document.activeElement).toBe(manual);
    expect(screen.getByTestId('until').textContent).toBe('manual');
});
