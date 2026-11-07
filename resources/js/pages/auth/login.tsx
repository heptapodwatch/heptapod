import { useTranslation } from 'react-i18next';
import { useEffect, useRef, useState } from 'react';
import { Form, Head, router } from '@inertiajs/react';
import type { FormComponentRef } from '@inertiajs/core';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasskeyVerify from '@/components/passkey-verify';
import TextLink from '@/components/text-link';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import LoginLayout from '@/layouts/auth/login-layout';
import { login } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    instance: string;
    status?: string;
    canResetPassword: boolean;
    devLogin?: { action: string; email: string; redirectUrl: string | null };
};

export default function Login({
    instance,
    status,
    canResetPassword,
    devLogin,
}: Props) {
    const { t } = useTranslation();
    const formRef = useRef<FormComponentRef>(null);
    const emailRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);
    const errorRef = useRef<HTMLParagraphElement>(null);
    const focusTarget = useRef<'email' | 'password' | null>(null);
    const [loginError, setLoginError] = useState<
        'throttled' | 'expired' | null
    >(null);
    const [refreshing, setRefreshing] = useState(false);
    useEffect(() => {
        if (loginError) errorRef.current?.focus();
    }, [loginError]);
    const focusAfterFinish = () => {
        requestAnimationFrame(() => {
            const target = focusTarget.current;
            focusTarget.current = null;
            if (target === 'email') emailRef.current?.focus();
            if (target === 'password') passwordRef.current?.focus();
        });
    };
    const refreshSession = () => {
        router.get(
            login(),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => setRefreshing(true),
                onSuccess: () => {
                    setLoginError(null);
                    focusTarget.current = 'password';
                },
                onFinish: () => {
                    setRefreshing(false);
                    focusAfterFinish();
                },
            },
        );
    };

    return (
        <>
            <Head title={t('Sign in')} />

            <header className="flex flex-col gap-1">
                <h1>{t('Sign in')}</h1>
                <p className="text-sm leading-[1.45] break-words text-muted-foreground">
                    {t('to :instance', { instance })}
                </p>
            </header>

            {status && (
                <Alert tone="info">
                    <AlertTitle>{status}</AlertTitle>
                </Alert>
            )}

            <div className="flex flex-col gap-[22px] empty:hidden [&>div]:my-0">
                <PasskeyVerify />
            </div>

            {loginError && (
                <div className="grid gap-3">
                    <InputError
                        ref={errorRef}
                        tabIndex={-1}
                        message={
                            loginError === 'throttled'
                                ? 'Too many attempts. Try again shortly.'
                                : 'Your session has expired. Refresh your session, then try again.'
                        }
                    />
                    {loginError === 'expired' && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={refreshing}
                            onClick={refreshSession}
                        >
                            {refreshing && <Spinner />}
                            {t('Refresh session')}
                        </Button>
                    )}
                </div>
            )}

            <Form
                noValidate
                ref={formRef}
                {...store.form()}
                resetOnError={['password']}
                resetOnSuccess={['password']}
                onStart={() => setLoginError(null)}
                onError={(errors) => {
                    focusTarget.current = errors.email
                        ? 'email'
                        : errors.password
                          ? 'password'
                          : null;
                }}
                onHttpException={(response) => {
                    if (response.status !== 429 && response.status !== 419)
                        return;
                    formRef.current?.reset('password');
                    formRef.current?.clearErrors();
                    setLoginError(
                        response.status === 429 ? 'throttled' : 'expired',
                    );
                    return false;
                }}
                onFinish={focusAfterFinish}
            >
                {({ processing, errors }) => (
                    <fieldset
                        disabled={processing || loginError === 'expired'}
                        aria-busy={processing}
                        className="grid min-w-0 gap-[22px]"
                    >
                        <div className="grid gap-[6px]">
                            <Label htmlFor="email" className="leading-[1.45]">
                                {t('Email address')}
                            </Label>
                            <Input
                                ref={emailRef}
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="email"
                                placeholder={t('email@example.com')}
                                aria-invalid={!!errors.email}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                                className="rounded-[7px] px-[10px] shadow-none dark:bg-transparent"
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>

                        <div className="grid gap-[6px]">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Label
                                    htmlFor="password"
                                    className="leading-[1.45]"
                                >
                                    {t('Password')}
                                </Label>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {t('Forgot your password?')}
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                ref={passwordRef}
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                                placeholder={t('Password')}
                                aria-invalid={!!errors.password}
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                                className="rounded-[7px] pr-10 pl-[10px] shadow-none dark:bg-transparent"
                            />
                            <InputError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>

                        <div className="flex items-center gap-3">
                            <Checkbox id="remember" name="remember" />
                            <Label
                                htmlFor="remember"
                                className="leading-[1.45]"
                            >
                                {t('Remember me')}
                            </Label>
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing || loginError === 'expired'}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            {processing ? t('Signing in…') : t('Sign in')}
                        </Button>
                    </fieldset>
                )}
            </Form>

            {devLogin && (
                <Form
                    noValidate
                    action={devLogin.action}
                    method="post"
                    transform={() => ({
                        email: devLogin.email,
                        redirect_url: devLogin.redirectUrl,
                    })}
                    className="grid gap-[6px] border-t border-border pt-[22px]"
                >
                    {({ processing }) => (
                        <>
                            <Button
                                type="submit"
                                variant="outline"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('Sign in as demo')}
                            </Button>
                            <p className="text-xs leading-[1.45] text-muted-foreground">
                                {t('Local development only')}
                            </p>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}

Login.layout = [LoginLayout];
