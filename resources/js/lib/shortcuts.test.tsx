import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { navigation } from '@/lib/navigation';
import { shortcutList, useGlobalShortcuts } from '@/lib/shortcuts';

const sites = navigation.find((entry) => entry.title === 'Sites')!;
const incidents = navigation.find((entry) => entry.title === 'Incidents')!;
let visit: ReturnType<typeof vi.spyOn>;
const onHelp = vi.fn();

function Harness({ children }: { children?: React.ReactNode }) {
    useGlobalShortcuts({ entries: [sites, incidents], onHelp });
    return <>{children}</>;
}

beforeEach(() => {
    visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    onHelp.mockClear();
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const press = (key: string, target: Element = document.body) =>
    fireEvent.keyDown(target, { key });

it('navigates with G then the destination key and opens the shortcut sheet with ?', () => {
    render(<Harness />);
    press('g');
    press('i');
    expect(visit).toHaveBeenCalledWith('/incidents');
    press('i');
    expect(visit).toHaveBeenCalledTimes(1);
    press('?');
    expect(onHelp).toHaveBeenCalledTimes(1);
});

it('activates the page create action with C', () => {
    const create = vi.fn();
    render(
        <Harness>
            <button type="button" data-shortcut-create onClick={create}>
                Add site
            </button>
        </Harness>,
    );
    // jsdom has no layout; the shortcut only clicks rendered controls.
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([
        {},
    ] as unknown as DOMRectList);
    press('c');
    expect(create).toHaveBeenCalledTimes(1);
});

it('leaves typing and open overlays alone', () => {
    const create = vi.fn();
    const { getByRole } = render(
        <Harness>
            <input aria-label="Search" />
            <button type="button" data-shortcut-create onClick={create}>
                Add site
            </button>
        </Harness>,
    );
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([
        {},
    ] as unknown as DOMRectList);
    const input = getByRole('textbox');
    press('g', input);
    press('s', input);
    press('c', input);
    press('?', input);
    expect(visit).not.toHaveBeenCalled();
    expect(create).not.toHaveBeenCalled();
    expect(onHelp).not.toHaveBeenCalled();

    const dialog = document.createElement('div');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('data-state', 'open');
    document.body.append(dialog);
    press('g');
    press('s');
    expect(visit).not.toHaveBeenCalled();
    dialog.remove();
});

it('lists every navigation shortcut once with a distinct key', () => {
    const keys = shortcutList(navigation)
        .filter((shortcut) => shortcut.group === 'Navigation')
        .map((shortcut) => shortcut.keys.join(' '));
    expect(keys.length).toBeGreaterThan(5);
    expect(new Set(keys).size).toBe(keys.length);
});
