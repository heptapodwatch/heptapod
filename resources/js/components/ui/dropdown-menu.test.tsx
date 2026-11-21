import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from './dropdown-menu';

afterEach(cleanup);

it('opens non-modal by default without locking body scroll and closes on Escape', async () => {
    render(
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button">Menu</button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
                <DropdownMenuItem>First action</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>,
    );

    const trigger = screen.getByRole('button', { name: 'Menu' });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'Enter' });

    expect(screen.getByRole('menuitem', { name: 'First action' })).toBeTruthy();
    expect(document.body.hasAttribute('data-scroll-locked')).toBe(false);
    expect(document.body.style.pointerEvents).not.toBe('none');

    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });

    expect(screen.queryByRole('menuitem', { name: 'First action' })).toBeNull();
    await waitFor(() => {
        expect(document.activeElement).toBe(trigger);
    });
});

it('still allows callers to opt into modal scroll lock explicitly', () => {
    render(
        <DropdownMenu defaultOpen modal>
            <DropdownMenuTrigger asChild>
                <button type="button">Modal menu</button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
                <DropdownMenuItem>Locked action</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>,
    );

    expect(
        screen.getByRole('menuitem', { name: 'Locked action' }),
    ).toBeTruthy();
    expect(document.body.getAttribute('data-scroll-locked')).toBe('1');
});
