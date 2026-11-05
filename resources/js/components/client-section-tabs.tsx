import { useTranslation } from 'react-i18next';
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

/** The one tab row of a client's pages. Notifications and Settings are for Owners and Admins; everyone reads Messages. */
export function ClientSectionTabs({
    clientId,
    value,
    canManage,
}: {
    clientId: number;
    value: ClientSection;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    return (
        <SectionTabs
            label={t('Client navigation')}
            value={value}
            items={[
                {
                    value: 'sites',
                    label: t('Sites'),
                    href: clientShow.url(clientId),
                },
                {
                    value: 'incidents',
                    label: t('Incidents'),
                    href: clientShow.url(clientId, {
                        query: { tab: 'incidents' },
                    }),
                },
                {
                    value: 'messages',
                    label: t('client_messages.tab'),
                    href: clientShow.url(clientId, {
                        query: { tab: 'messages' },
                    }),
                },
                ...(canManage
                    ? [
                          {
                              value: 'notifications',
                              label: t('Notifications'),
                              href: clientNotifications.url(clientId),
                          },
                          {
                              value: 'settings',
                              label: t('Settings'),
                              href: clientSettings.url(clientId),
                          },
                      ]
                    : []),
            ]}
        />
    );
}
