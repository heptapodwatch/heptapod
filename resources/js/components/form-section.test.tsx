import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import { FormSection } from './form-section';

vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t }) };
});
afterEach(cleanup);

it('shows one description sentence and keeps the detail behind a keyboard-reachable info tooltip', async () => {
    render(
        <TooltipProvider>
            <FormSection
                title="Delivery"
                description="Used for invites."
                detail="Credentials are never shown."
            >
                <p>Body</p>
            </FormSection>
        </TooltipProvider>,
    );
    expect(screen.getByText('Used for invites.')).not.toBeNull();
    expect(screen.queryByText('Credentials are never shown.')).toBeNull();
    const info = screen.getByRole('button', { name: 'More information' });
    await act(async () => {
        fireEvent.focus(info);
    });
    expect(
        (await screen.findAllByText('Credentials are never shown.')).length,
    ).toBeGreaterThan(0);
});

it('renders no info button without detail', () => {
    render(
        <TooltipProvider>
            <FormSection title="Delivery" description="Used for invites.">
                <p>Body</p>
            </FormSection>
        </TooltipProvider>,
    );
    expect(
        screen.queryByRole('button', { name: 'More information' }),
    ).toBeNull();
});
