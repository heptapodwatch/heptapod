import { useTranslation } from 'react-i18next';
// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    const { t } = useTranslation();
    return (
        <>
            <Head title={t('Email verification')} />

            {status === 'verification-link-sent' && (
                <Alert tone="info">
                    <AlertTitle>
                        {t(
                            'A new verification link has been sent to the email address you provided during registration.',
                        )}
                    </AlertTitle>
                </Alert>
            )}

            <Form noValidate {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            {t('Resend verification email')}
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            {t('Log out')}
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Email verification',
    description:
        'Please verify your email address by clicking on the link we just emailed to you.',
};
