import { useTranslation } from 'react-i18next';
import { ClientMark } from '@/components/client-mark';
import {
    ClientSectionTabs,
    type ClientSection,
} from '@/components/client-section-tabs';
import { PageHeader } from '@/components/page-header';

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
                    client.contact_name || client.archived_at ? (
                        <>
                            {client.contact_name && (
                                <span>
                                    {t('Contact name')}: {client.contact_name}
                                </span>
                            )}
                            {client.archived_at && (
                                <span>
                                    {t(
                                        'This client is archived. Monitoring is paused.',
                                    )}
                                </span>
                            )}
                        </>
                    ) : undefined
                }
            />
            <ClientSectionTabs
                clientId={client.id}
                value={section}
                canManage={canManage}
            />
        </>
    );
}
