import type { ComponentProps } from 'react';
import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { TeamAlertSensitivity } from '@/components/alert-sensitivity-form';
import { PageHeader } from '@/components/page-header';

export default function Alerts(
    props: ComponentProps<typeof TeamAlertSensitivity>,
) {
    const { t } = useTranslation();
    return (
        <>
            <Head title={t('alert_sensitivity.title')} />
            <PageHeader
                title={t('alert_sensitivity.title')}
                description={t('alert_sensitivity.page_description')}
            />
            <TeamAlertSensitivity
                key={`${props.saved.alert_after_minutes}-${props.saved.alert_recovery_checks}`}
                {...props}
            />
        </>
    );
}
