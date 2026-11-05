import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ClientHeader } from '@/components/client-header';
import { PageContainer } from '@/components/page-container';
import type { SettingsPage } from '@/components/recipient-settings/model';
import { RecipientSettings } from '@/components/recipient-settings/recipient-settings';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/** One client's channels: where it can be reached, and what it hears without a manual send (design §7.1). */
export default function ClientNotifications({
    settings,
    client,
}: {
    settings: SettingsPage;
    client: Heptapod.Data.Ui.ClientData;
}) {
    const { t } = useTranslation();
    return (
        <>
            <Head title={`${client.name} · ${t('Notifications')}`} />
            <PageContainer>
                <ClientHeader
                    client={client}
                    section="notifications"
                    canManage
                />
                <RecipientSettings
                    page={settings}
                    scopeDescription={t('recipient_settings.client.scope', {
                        count: settings.sites.length,
                    })}
                    notice={
                        <Alert tone="info">
                            <AlertTitle>
                                {t('recipient_settings.client.notice_title')}
                            </AlertTitle>
                            <AlertDescription>
                                {t('recipient_settings.client.notice_body')}
                            </AlertDescription>
                        </Alert>
                    }
                />
            </PageContainer>
        </>
    );
}
