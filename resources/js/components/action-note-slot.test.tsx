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
    Object.defineProperty(element, 'scrollWidth', { value: scrollWidth });
    Object.defineProperty(element, 'clientWidth', { value: clientWidth });
}

it('reserves its single-line slot when empty and keeps full text for aria-describedby and TruncatedText when populated', async () => {
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

    const truncated = slot!.firstElementChild!;
    measure(truncated, 260, 140);
    await act(async () => {
        fireEvent.focus(truncated);
    });
    expect(screen.getByRole('tooltip').textContent).toBe(fullText);
});

it('reserves an ActionNoteSlot in PageHeader only when reserveActionNote or actionNote is passed', () => {
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
            actionNoteId="check-run-note"
            reserveActionNote
        />,
    );
    const slot = container.querySelector('[data-slot="action-note-slot"]');
    expect(slot).not.toBeNull();
    expect(slot?.id).toBe('check-run-note');
});
