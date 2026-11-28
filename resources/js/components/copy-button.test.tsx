import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { CopyButton } from '@/components/copy-button';

vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
const warning = vi.hoisted(() => vi.fn());
vi.mock('sonner', () => ({ toast: { warning } }));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    warning.mockClear();
});

it('confirms a copy, announces it and resets after 2 s; a refused clipboard says to copy by hand', async () => {
    vi.useFakeTimers();
    const writeText = vi.fn(async () => {});
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    render(<CopyButton value="cd /opt/heptapod" />);

    await act(async () =>
        fireEvent.click(screen.getByRole('button', { name: 'Copy' })),
    );
    expect(writeText).toHaveBeenCalledWith('cd /opt/heptapod');
    expect(screen.getByRole('button', { name: 'Copied' })).toBeTruthy();
    expect(screen.getByRole('status').textContent).toBe('Copied');
    act(() => {
        vi.advanceTimersByTime(2000);
    });
    expect(screen.getByRole('button', { name: 'Copy' })).toBeTruthy();
    expect(screen.getByRole('status').textContent).toBe('');

    writeText.mockRejectedValueOnce(new Error('NotAllowedError'));
    const button = screen.getByRole('button', { name: 'Copy' });
    await act(async () => fireEvent.click(button));
    const note = screen.getByText('errors.clipboard_unavailable');
    expect(button.getAttribute('aria-describedby')).toBe(
        note.closest('[data-slot="action-note-slot"]')?.id,
    );
});

it('names the icon-only copy and toasts a refusal, since an inline value has no room for a note', async () => {
    vi.stubGlobal('navigator', {});
    render(<CopyButton value="49.12.88.204" iconLabel="Copy IP address" />);

    await act(async () =>
        fireEvent.click(
            screen.getByRole('button', { name: 'Copy IP address' }),
        ),
    );
    expect(warning).toHaveBeenCalledWith('errors.clipboard_unavailable');
    expect(screen.getByRole('status').textContent).toBe('');
});
