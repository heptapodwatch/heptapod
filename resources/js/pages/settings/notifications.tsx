import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { MailConfigurationNotice } from '@/components/mail-configuration-notice';
import { PageHeader } from '@/components/page-header';
import type { SettingsPage } from '@/components/recipient-settings/model';
import { RecipientSettings } from '@/components/recipient-settings/recipient-settings';

/** The signed-in person's own channels: the account email plus any extra channel (design §7.1). */
export default function MyNotifications({
    settings,
}: {
    settings: SettingsPage;
}) {
    const { t } = useTranslation();
    return (
        <>
            <Head title={t('My notifications')} />
            <PageHeader
                title={t('My notifications')}
                description={t('recipient_settings.me.description')}
                notices={<MailConfigurationNotice />}
            />
            <RecipientSettings
                page={settings}
                scopeDescription={t('recipient_settings.me.scope', {
                    count: settings.sites.length,
                })}
            />
        </>
    );
}
