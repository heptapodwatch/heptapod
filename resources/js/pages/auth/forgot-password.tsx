import { useTranslation } from 'react-i18next';
// Components
import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import LoginLayout from '@/layouts/auth/login-layout';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    const { t } = useTranslation();
    return (
        <>
            <Head title={t('Forgot password')} />

            <header className="flex flex-col gap-1">
                <h1>{t('Forgot password')}</h1>
                <p className="text-sm leading-[1.45] text-muted-foreground">
                    {t('We email you a link to reset your password.')}
                </p>
            </header>

            {status && (
                <Alert tone="info">
                    <AlertTitle>{status}</AlertTitle>
                </Alert>
            )}

            <Form noValidate {...email.form()}>
                {({ processing, errors }) => (
                    <div className="grid gap-[22px]">
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">{t('Email address')}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="off"
                                autoFocus
                                placeholder={t('email@example.com')}
                                aria-invalid={!!errors.email}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>

                        <Button
                            className="w-full"
                            disabled={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {processing && <Spinner />}
                            {t('Send reset link')}
                        </Button>
                    </div>
                )}
            </Form>

            <div className="text-center text-sm text-muted-foreground">
                <TextLink href={login()}>{t('Back to sign in')}</TextLink>
            </div>
        </>
    );
}

ForgotPassword.layout = [LoginLayout];
