import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import ConfirmPassword from '@/pages/auth/confirm-password';
import ForgotPassword from '@/pages/auth/forgot-password';
import ResetPassword from '@/pages/auth/reset-password';
import VerifyEmail from '@/pages/auth/verify-email';

// The Inertia Form hands its render prop the server's validation errors; the test supplies them directly.
const formState: { errors: Record<string, string> } = { errors: {} };
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Form: ({
        children,
    }: {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
        }) => ReactNode;
    }) => <form>{children({ processing: false, ...formState })}</form>,
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/components/passkey-verify', () => ({ default: () => null }));
afterEach(() => {
    cleanup();
    formState.errors = {};
});

it('announces the forgot-password status through the info Alert, not a raw green line', () => {
    render(
        <ForgotPassword status="We have emailed your password reset link." />,
    );

    const status = screen.getByRole('status');
    expect(status.getAttribute('data-slot')).toBe('alert');
    expect(status.getAttribute('data-tone')).toBe('info');
    expect(status.textContent).toContain(
        'We have emailed your password reset link.',
    );
});

it('announces the resent verification link through the info Alert', () => {
    render(<VerifyEmail status="verification-link-sent" />);

    expect(screen.getByRole('status').getAttribute('data-tone')).toBe('info');
});

it('marks every invalid auth field and names its error, as the invitation form does', () => {
    formState.errors = {
        email: 'We can’t find a user with that email address.',
    };
    render(<ForgotPassword />);
    const email = screen.getByLabelText('Email address');
    expect(email.getAttribute('aria-invalid')).toBe('true');
    expect(
        document.getElementById(email.getAttribute('aria-describedby') ?? '')
            ?.textContent,
    ).toBe('We can’t find a user with that email address.');
    cleanup();

    formState.errors = {
        password: 'The password field must be at least 12 characters.',
        password_confirmation: 'The password confirmation does not match.',
    };
    render(<ResetPassword token="t" email="a@example.test" passwordRules="" />);
    for (const [label, message] of [
        ['Password', formState.errors.password],
        ['Confirm password', formState.errors.password_confirmation],
    ] as const) {
        const field = screen.getByLabelText(label, { selector: 'input' });
        expect(field.getAttribute('aria-invalid')).toBe('true');
        expect(
            document.getElementById(
                field.getAttribute('aria-describedby') ?? '',
            )?.textContent,
        ).toBe(message);
    }
    cleanup();

    formState.errors = { password: 'The provided password is incorrect.' };
    render(<ConfirmPassword />);
    expect(
        screen
            .getByLabelText('Password', { selector: 'input' })
            .getAttribute('aria-invalid'),
    ).toBe('true');
});
