import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Security from '@/pages/settings/security';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ props: {} }),
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/components/manage-two-factor', () => ({ default: () => null }));
vi.mock('@/components/manage-passkeys', () => ({ default: () => null }));
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

type ComponentPropsOf<T> = T extends (props: infer P) => unknown ? P : never;

const renderSecurity = () => {
    render(
        <Security
            {...({ passwordRules: '' } as ComponentPropsOf<typeof Security>)}
        />,
    );
    return {
        save: screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement,
        discard: screen.getByRole('button', {
            name: 'Discard',
        }) as HTMLButtonElement,
        current: screen.getByLabelText('Current password') as HTMLInputElement,
    };
};

const type = (input: HTMLInputElement, value: string) =>
    fireEvent.input(input, { target: { value } });

it('keeps the password Save and Discard disabled until a field changes, like every other settings form', () => {
    const { save, discard, current } = renderSecurity();
    expect(screen.getByText('No unsaved changes')).not.toBeNull();
    expect(save.disabled).toBe(true);
    expect(discard.disabled).toBe(true);

    type(current, 'old-secret');

    expect(screen.getAllByText('Unsaved changes').length).toBeGreaterThan(0);
    expect(save.disabled).toBe(false);
    expect(discard.disabled).toBe(false);
});

it('clears the typed passwords on Discard and disables both actions again', () => {
    const { save, discard, current } = renderSecurity();
    type(current, 'old-secret');

    fireEvent.click(discard);

    expect(current.value).toBe('');
    expect(screen.getByText('No unsaved changes')).not.toBeNull();
    expect(save.disabled).toBe(true);
    expect(discard.disabled).toBe(true);
});

it('clears the passwords after a successful save and disables both actions again', async () => {
    const post = vi
        .spyOn(router, 'post')
        .mockImplementation((_url, _data, options) => {
            options?.onSuccess?.(
                {} as Parameters<NonNullable<typeof options.onSuccess>>[0],
            );
            options?.onFinish?.(
                {} as Parameters<NonNullable<typeof options.onFinish>>[0],
            );
        });
    const { save, discard, current } = renderSecurity();
    type(current, 'old-secret');
    type(
        screen.getByLabelText('New password') as HTMLInputElement,
        'new-secret-123',
    );

    await act(async () => {
        fireEvent.click(save);
    });

    expect(post).toHaveBeenCalledOnce();
    expect(current.value).toBe('');
    expect(screen.getByText('No unsaved changes')).not.toBeNull();
    expect(save.disabled).toBe(true);
    expect(discard.disabled).toBe(true);
});
