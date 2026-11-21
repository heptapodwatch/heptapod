import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { ActionNoteSlot } from '@/components/action-note-slot';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

afterEach(cleanup);

function measure(element: Element, scrollWidth: number, clientWidth: number) {
    Object.defineProperty(element, 'scrollWidth', {
        value: scrollWidth,
        configurable: true,
    });
    Object.defineProperty(element, 'clientWidth', {
        value: clientWidth,
        configurable: true,
    });
}

it('reserves its single-line slot when empty and reveals clipped notes on hover, focus, and touch tap', async () => {
    const { rerender } = render(
        <>
            <Button type="button">Run check</Button>
            <ActionNoteSlot id="run-note" />
        </>,
    );

    const slot = document.getElementById('run-note');
    expect(slot).not.toBeNull();
    expect(slot?.getAttribute('data-slot')).toBe('action-note-slot');
    expect(slot?.textContent).toBe('');
    expect(slot?.querySelector('[role="button"]')).toBeNull();

    const fullText = 'Erneut ausführbar um 14:25 · in 5 Min.';
    rerender(
        <>
            <Button type="button" disabled aria-describedby="run-note">
                Run check
            </Button>
            <ActionNoteSlot id="run-note">
                <p role="status">{fullText}</p>
            </ActionNoteSlot>
        </>,
    );

    const button = screen.getByRole('button', { name: 'Run check' });
    expect(button.getAttribute('aria-describedby')).toBe('run-note');
    expect(document.getElementById('run-note')?.textContent).toBe(fullText);
    // Fitting text gets no interactive disclosure trigger.
    expect(slot?.querySelector('[role="button"]')).toBeNull();

    // Simulate clipping so the shared disclosure trigger activates.
    const measured = slot!.firstElementChild!;
    measure(measured, 260, 140);
    rerender(
        <>
            <Button type="button" disabled aria-describedby="run-note">
                Run check
            </Button>
            <ActionNoteSlot id="run-note">
                <p role="status">{`${fullText} `}</p>
            </ActionNoteSlot>
        </>,
    );

    const trigger = slot!.querySelector('[role="button"]')!;
    expect(trigger).not.toBeNull();

    // Touch tap opens and keeps the disclosure open; second tap closes it.
    await act(async () => {
        fireEvent.pointerDown(trigger, { pointerType: 'touch' });
        fireEvent.click(trigger);
    });
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(document.body.textContent).toContain(fullText);

    await act(async () => {
        fireEvent.click(trigger);
    });
    expect(trigger.getAttribute('aria-expanded')).toBe('false');

    // Mouse hover opens and pointer leave closes.
    await act(async () => {
        fireEvent.pointerEnter(trigger, { pointerType: 'mouse' });
    });
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    await act(async () => {
        fireEvent.pointerLeave(trigger, { pointerType: 'mouse' });
    });
    expect(trigger.getAttribute('aria-expanded')).toBe('false');

    // Keyboard focus opens and blur closes.
    await act(async () => {
        fireEvent.focus(trigger);
    });
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    await act(async () => {
        fireEvent.blur(trigger);
    });
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
});

it('reserves an ActionNoteSlot inside PageHeader action column only when actionNote is passed', () => {
    const { container, rerender } = render(
        <PageHeader
            title="Sites"
            description="Portfolio overview."
            primaryAction={<Button type="button">Add site</Button>}
        />,
    );
    expect(
        container.querySelector('[data-slot="action-note-slot"]'),
    ).toBeNull();

    rerender(
        <PageHeader
            headingLevel={2}
            title="Site is reachable."
            description="Results, history and settings for this check."
            primaryAction={<Button type="button">Run check</Button>}
            actionNote={{ id: 'check-run-note' }}
        />,
    );
    const slot = container.querySelector('[data-slot="action-note-slot"]');
    expect(slot).not.toBeNull();
    expect(slot?.id).toBe('check-run-note');
});
