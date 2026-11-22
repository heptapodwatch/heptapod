import { useTranslation } from 'react-i18next';
import { ClientMark } from '@/components/client-mark';
import { PageHeader } from '@/components/page-header';
import { SectionTabs } from '@/components/section-tabs';
import {
    notifications as clientNotifications,
    settings as clientSettings,
    show as clientShow,
} from '@/routes/clients';

export type ClientSection =
    | 'sites'
    | 'incidents'
    | 'messages'
    | 'notifications'
    | 'settings';

export function ClientHeader({
    client,
    section,
    canManage,
}: {
    client: Heptapod.Data.Ui.ClientData;
    section: ClientSection;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    return (
        <>
            <PageHeader
                title={client.name}
                description={t('pages.client.header_description')}
                leading={<ClientMark client={client} />}
                meta={
                    client.archived_at ? (
                        <span>
                            {t(
                                'This client is archived. Monitoring is paused.',
                            )}
                        </span>
                    ) : undefined
                }
            />
            <SectionTabs
                label={t('Client navigation')}
                value={section}
                items={[
                    {
                        value: 'sites',
                        label: t('Sites'),
                        href: clientShow.url(client.id),
                    },
                    {
                        value: 'incidents',
                        label: t('Incidents'),
                        href: clientShow.url(client.id, {
                            query: { tab: 'incidents' },
                        }),
                    },
                    {
                        value: 'messages',
                        label: t('client_messages.tab'),
                        href: clientShow.url(client.id, {
                            query: { tab: 'messages' },
                        }),
                    },
                    ...(canManage
                        ? [
                              {
                                  value: 'notifications',
                                  label: t('Notifications'),
                                  href: clientNotifications.url(client.id),
                              },
                              {
                                  value: 'settings',
                                  label: t('Settings'),
                                  href: clientSettings.url(client.id),
                              },
                          ]
                        : []),
                ]}
            />
        </>
    );
}
