import { usePage } from '@inertiajs/react';
import { TextLink } from '@/components/text-link';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export function MailConfigurationNotice() {
    const { mailLogOnly, mailMayOnlyBeLogged, mailConfigurationAction } =
        usePage().props;
    const { t } = useTranslation();
    if (!mailMayOnlyBeLogged) return null;

    return (
        <Alert
            tone="warning"
            action={
                <TextLink document href={mailConfigurationAction.href}>
                    {t(mailConfigurationAction.action_key)}
                </TextLink>
            }
        >
            <AlertTitle>
                {t(
                    mailLogOnly
                        ? 'Email delivery is not configured'
                        : 'Some email may only be logged',
                )}
            </AlertTitle>
            {mailLogOnly && (
                <AlertDescription>
                    {t('Logged, not delivered')}
                </AlertDescription>
            )}
        </Alert>
    );
}
